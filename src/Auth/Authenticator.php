<?php

namespace LaraGram\Sentinel\Auth;

use LaraGram\Http\Request;
use LaraGram\Sentinel\Contracts\EntriesRepository;
use LaraGram\Sentinel\Contracts\MetricsRepository;
use LaraGram\Sentinel\EntryType;
use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Sentinel\Telegram\BotInspector;
use LaraGram\Support\Facades\RateLimiter;
use LaraGram\Support\Str;

class Authenticator
{
    /**
     * The session key holding the logged in admin.
     */
    public const SESSION_KEY = 'sentinel_auth';

    /**
     * Create a new authenticator.
     *
     * @param  \LaraGram\Sentinel\Telegram\BotInspector  $bots
     * @param  \LaraGram\Sentinel\Contracts\EntriesRepository  $entries
     * @param  \LaraGram\Sentinel\Contracts\MetricsRepository  $metrics
     * @return void
     */
    public function __construct(
        protected BotInspector $bots,
        protected EntriesRepository $entries,
        protected MetricsRepository $metrics,
    ) {
    }

    /**
     * Get the login methods that are configured.
     *
     * @return array<int, string>
     */
    public function methods(): array
    {
        return array_values(array_filter((array) config('sentinel.auth.methods', []), fn ($method) => match ($method) {
            'telegram' => $this->admins() !== [] && $this->bots->defaultConnection() !== null,
            'password' => (string) config('sentinel.auth.password') !== '',
            default => false,
        }));
    }

    /**
     * Determine if any login method is configured.
     *
     * @return bool
     */
    public function configured(): bool
    {
        return $this->methods() !== [];
    }

    /**
     * Get the Telegram user IDs allowed to log in.
     *
     * @return array<int, string>
     */
    public function admins(): array
    {
        return array_values(array_map('strval', (array) config('sentinel.auth.admins', [])));
    }

    /**
     * Get the admin logged in on this session, if the session is still valid.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return array|null
     */
    public function user(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $user = $request->session()->get(static::SESSION_KEY);

        if (! is_array($user) || ! in_array($user['via'] ?? null, $this->methods(), true)) {
            return null;
        }

        $lifetime = (int) config('sentinel.auth.lifetime', 720) * 60;

        if ($lifetime > 0 && time() - (int) ($user['at'] ?? 0) > $lifetime) {
            $request->session()->forget(static::SESSION_KEY);

            return null;
        }

        $stillAllowed = match ($user['via']) {
            'telegram' => in_array((string) ($user['id'] ?? ''), $this->admins(), true),
            'password' => hash_equals((string) config('sentinel.auth.username'), (string) ($user['id'] ?? '')),
            default => false,
        };

        return $stillAllowed ? $user : null;
    }

    /**
     * Send a one-time login code to a Telegram admin.
     *
     * The answer is the same whether or not the ID belongs to an admin, so
     * the login form cannot be used to find out who the admins are.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  string  $identifier  A Telegram user ID, or the @username of a known user.
     * @return array{ok: bool, id?: string, error?: string}
     */
    public function sendCode(Request $request, string $identifier): array
    {
        if ($error = $this->throttled($request)) {
            return ['ok' => false, 'error' => $error];
        }

        $id = $this->resolveTelegramId($identifier);

        if ($id === null) {
            return ['ok' => false, 'error' => 'Enter your numeric Telegram user ID.'];
        }

        if (in_array($id, $this->admins(), true)) {
            $code = (string) random_int(100000, 999999);

            cache()->put($this->codeKey($id), [
                'hash' => hash('sha256', $code),
                'attempts' => 0,
            ], (int) config('sentinel.auth.code_ttl', 300));

            $minutes = max(1, intdiv((int) config('sentinel.auth.code_ttl', 300), 60));

            $response = $this->bots->call($this->bots->defaultConnection(), 'sendMessage', [
                'chat_id' => $id,
                'parse_mode' => 'HTML',
                'text' => "🔐 <b>Sentinel login code</b>\n\n<code>{$code}</code>\n\n"
                    ."Requested from <code>{$this->e($request->ip())}</code> · {$this->e($this->agent($request))}\n"
                    ."It expires in {$minutes} minutes. If this was not you, ignore this message.",
            ]);

            if (! ($response['ok'] ?? false)) {
                return ['ok' => false, 'error' => 'The bot could not message you. Start a chat with the bot first, then try again.'];
            }
        }

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Log in with a one-time code.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  string  $id
     * @param  string  $code
     * @return array{ok: bool, error?: string}
     */
    public function verifyCode(Request $request, string $id, string $code): array
    {
        if ($error = $this->throttled($request)) {
            return ['ok' => false, 'error' => $error];
        }

        $key = $this->codeKey($id);
        $pending = cache()->get($key);

        if (! is_array($pending) || ! in_array($id, $this->admins(), true)) {
            return ['ok' => false, 'error' => 'This code has expired. Ask for a new one.'];
        }

        if (! hash_equals($pending['hash'], hash('sha256', preg_replace('/\D/', '', $code)))) {
            $pending['attempts']++;

            if ($pending['attempts'] >= (int) config('sentinel.auth.max_attempts', 5)) {
                cache()->forget($key);

                return ['ok' => false, 'error' => 'Too many wrong codes. Ask for a new one.'];
            }

            cache()->put($key, $pending, (int) config('sentinel.auth.code_ttl', 300));

            return ['ok' => false, 'error' => 'That code is not right.'];
        }

        cache()->forget($key);

        $known = $this->metrics->values('user', [$id])->first()?->value ?? [];

        $this->login($request, [
            'via' => 'telegram',
            'id' => $id,
            'name' => trim(($known['first_name'] ?? '').' '.($known['last_name'] ?? '')) ?: 'Telegram admin',
            'username' => $known['username'] ?? null,
        ]);

        return ['ok' => true];
    }

    /**
     * Log in with the configured username and password.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  string  $username
     * @param  string  $password
     * @return array{ok: bool, error?: string}
     */
    public function attemptPassword(Request $request, string $username, string $password): array
    {
        if ($error = $this->throttled($request)) {
            return ['ok' => false, 'error' => $error];
        }

        $expected = (string) config('sentinel.auth.password');
        $validUser = hash_equals((string) config('sentinel.auth.username'), $username);

        $validPassword = preg_match('/^\$(2[aby]|argon2i|argon2id)\$/', $expected)
            ? password_verify($password, $expected)
            : hash_equals($expected, $password);

        if ($expected === '' || ! $validUser || ! $validPassword) {
            return ['ok' => false, 'error' => 'These credentials do not match.'];
        }

        $this->login($request, [
            'via' => 'password',
            'id' => $username,
            'name' => $username,
            'username' => null,
        ]);

        return ['ok' => true];
    }

    /**
     * Start an authenticated session.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  array  $user
     * @return void
     */
    protected function login(Request $request, array $user): void
    {
        RateLimiter::clear($this->throttleKey($request));

        $request->session()->regenerate();
        $request->session()->put(static::SESSION_KEY, $user + ['at' => time(), 'ip' => $request->ip()]);

        $this->record($request, $user);
        $this->notify($request, $user);
    }

    /**
     * End the authenticated session.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return void
     */
    public function logout(Request $request): void
    {
        $request->session()->forget(static::SESSION_KEY);
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
    }

    /**
     * Count an attempt and return an error when there were too many.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return string|null
     */
    protected function throttled(Request $request): ?string
    {
        $key = $this->throttleKey($request);
        $max = (int) config('sentinel.auth.max_attempts', 5);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.';
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    /**
     * Get the rate limiter key of a request.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return string
     */
    protected function throttleKey(Request $request): string
    {
        return 'sentinel-login:'.$request->ip();
    }

    /**
     * Get the cache key of a pending code.
     *
     * @param  string  $id
     * @return string
     */
    protected function codeKey(string $id): string
    {
        return 'sentinel:login-code:'.$id;
    }

    /**
     * Resolve a numeric ID, or the @username of a user who talked to the bot.
     *
     * @param  string  $identifier
     * @return string|null
     */
    protected function resolveTelegramId(string $identifier): ?string
    {
        $identifier = trim($identifier);

        if (preg_match('/^\d{3,20}$/', $identifier)) {
            return $identifier;
        }

        $username = strtolower(ltrim($identifier, '@'));

        if ($username === '' || ! preg_match('/^\w{4,32}$/', $username)) {
            return null;
        }

        $match = $this->metrics->values('user', $this->admins())
            ->first(fn ($user) => strtolower((string) ($user->value['username'] ?? '')) === $username);

        return $match ? (string) $match->key : null;
    }

    /**
     * Record the login as an entry.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  array  $user
     * @return void
     */
    protected function record(Request $request, array $user): void
    {
        rescue(fn () => $this->entries->store(collect([
            IncomingEntry::make([
                'kind' => 'login',
                'title' => '🔓 Dashboard login',
                'message' => "{$user['name']} logged in with {$user['via']} from {$request->ip()} ({$this->agent($request)})",
                'chat_ids' => [],
                'delivered' => 1,
                'entry' => null,
            ])->type(EntryType::ALERT)->batchId((string) Str::orderedUuid())->tags([
                'alert:login',
                'login:'.$user['via'],
                $user['via'] === 'telegram' ? 'user:'.$user['id'] : null,
            ]),
        ])), report: false);
    }

    /**
     * Tell the admins on Telegram that someone logged in.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  array  $user
     * @return void
     */
    protected function notify(Request $request, array $user): void
    {
        if (! config('sentinel.auth.notify') || ($connection = $this->bots->defaultConnection()) === null) {
            return;
        }

        $text = "🔓 <b>Sentinel login</b>\n\n<b>{$this->e($user['name'])}</b> logged in with {$user['via']}\n"
            ."IP <code>{$this->e($request->ip())}</code> · {$this->e($this->agent($request))}";

        foreach ($this->admins() as $admin) {
            Sentinel::withoutRecording(fn () => $this->bots->call($connection, 'sendMessage', [
                'chat_id' => $admin,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]));
        }
    }

    /**
     * Describe the browser of a request in a few words.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return string
     */
    protected function agent(Request $request): string
    {
        $agent = (string) $request->userAgent();

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'a browser',
        };

        $os = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $os ? "{$browser} on {$os}" : $browser;
    }

    /**
     * Escape text for Telegram HTML.
     *
     * @param  string|null  $value
     * @return string
     */
    protected function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
