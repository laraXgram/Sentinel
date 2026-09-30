<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Log in · Sentinel</title>
    <link rel="icon" type="image/svg+xml" href="{{ $base }}/assets/img/favicon.svg?v={{ $version }}">
    <link rel="stylesheet" href="{{ $base }}/assets/css/sentinel.css?v={{ $version }}">
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('sentinel.theme');
                if (theme === 'dark' || (! theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        })();
    </script>
</head>
<body>
<div class="auth">
    <main class="auth-main">
        <div class="auth-form">
            <a class="auth-brand" href="{{ $base }}/login">
                <img src="{{ $base }}/assets/img/favicon.svg?v={{ $version }}" alt="" width="44" height="44">
                <span><strong>Sentinel</strong><small>{{ $app }}</small></span>
            </a>

            <h1 class="auth-title">Sign in</h1>

            @if (count($methods) === 0)
                <p class="auth-sub">Login is not set up yet, so the dashboard only opens in the <code>local</code> environment.</p>

                <div class="callout" style="margin-top:24px">
                    <div>
                        <div class="strong" style="margin-bottom:6px">Add at least one method to your .env file</div>
                        <pre class="code" style="margin:8px 0 10px;max-height:none"># Telegram: the bot sends these admins a one-time code
SENTINEL_ADMINS=123456789,987654321

# Or a username and password (a bcrypt hash works too)
SENTINEL_USERNAME=admin
SENTINEL_PASSWORD=choose-a-long-password</pre>
                        <div class="small muted">Then reload this page. Logins are always required once a method is set, locally too.</div>
                    </div>
                </div>
            @else
                <p class="auth-sub">
                    @if ($method === 'telegram' && $step === 'code')
                        Enter the code the bot sent to your Telegram account.
                    @else
                        Watch over your bot. Log in to continue.
                    @endif
                </p>

                @if ($error)
                    <div class="issue error" style="margin-top:20px"><div class="grow"><div class="issue-title">{{ $error }}</div></div></div>
                @endif

                @if ($notice)
                    <div class="issue success" style="margin-top:20px"><div class="grow"><div class="issue-detail" style="margin:0;color:var(--text)">{{ $notice }}</div></div></div>
                @endif

                @if (count($methods) > 1 && ! ($method === 'telegram' && $step === 'code'))
                    <div class="segmented auth-tabs" role="tablist">
                        @foreach ($methods as $option)
                            <button type="button" class="{{ $option === $method ? 'active' : '' }}" data-tab="{{ $option }}">
                                {{ $option === 'telegram' ? 'Telegram code' : 'Password' }}
                            </button>
                        @endforeach
                    </div>
                @endif

                @if (in_array('telegram', $methods, true))
                    <div class="auth-panel" data-panel="telegram" @if ($method !== 'telegram') hidden @endif>
                        @if ($step === 'code')
                            <form method="POST" action="{{ $base }}/login/verify" class="auth-fields">
                                <input type="hidden" name="_token" value="{{ $token }}">
                                <input type="hidden" name="telegram_id" value="{{ $telegramId }}">
                                <div class="field">
                                    <label for="code">Login code</label>
                                    <input class="input auth-code" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="••••••" required autofocus>
                                    <span class="field-hint">Sent to Telegram account <span class="mono">{{ $telegramId }}</span>. It expires in a few minutes.</span>
                                </div>
                                <button class="btn primary auth-submit" type="submit">Log in</button>
                            </form>
                            <div class="auth-links">
                                <form method="POST" action="{{ $base }}/login/code">
                                    <input type="hidden" name="_token" value="{{ $token }}">
                                    <input type="hidden" name="telegram_id" value="{{ $telegramId }}">
                                    <button type="submit" class="auth-link">Send a new code</button>
                                </form>
                                <a class="auth-link" href="{{ $base }}/login">Use another account</a>
                            </div>
                        @else
                            <form method="POST" action="{{ $base }}/login/code" class="auth-fields">
                                <input type="hidden" name="_token" value="{{ $token }}">
                                <div class="field">
                                    <label for="telegram_id">Telegram user ID</label>
                                    <input class="input" id="telegram_id" name="telegram_id" value="{{ $telegramId }}" placeholder="123456789 or @username" autocomplete="username" required autofocus>
                                    <span class="field-hint">
                                        Must be listed in <code>SENTINEL_ADMINS</code>.
                                        @if ($bot)
                                            Start a chat with <a class="auth-link" href="https://t.me/{{ $bot }}" target="_blank" rel="noopener">&#64;{{ $bot }}</a> first so it can message you.
                                        @endif
                                    </span>
                                </div>
                                <button class="btn primary auth-submit" type="submit">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.54 21.69a.5.5 0 0 0 .94-.03l6.5-19a.5.5 0 0 0-.64-.64l-19 6.5a.5.5 0 0 0-.03.94l7.93 3.18a2 2 0 0 1 1.11 1.11z"/><path d="m21.85 2.15-10.94 10.93"/></svg>
                                    Send me a code on Telegram
                                </button>
                            </form>
                        @endif
                    </div>
                @endif

                @if (in_array('password', $methods, true))
                    <div class="auth-panel" data-panel="password" @if ($method !== 'password') hidden @endif>
                        <form method="POST" action="{{ $base }}/login/password" class="auth-fields">
                            <input type="hidden" name="_token" value="{{ $token }}">
                            <div class="field">
                                <label for="username">Username</label>
                                <input class="input" id="username" name="username" value="{{ $username }}" autocomplete="username" required @if ($method === 'password') autofocus @endif>
                            </div>
                            <div class="field">
                                <label for="password">Password</label>
                                <div class="auth-password">
                                    <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
                                    <button type="button" class="auth-reveal" data-reveal aria-label="Show password">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/></svg>
                                    </button>
                                </div>
                            </div>
                            <button class="btn primary auth-submit" type="submit">Log in</button>
                        </form>
                    </div>
                @endif
            @endif
        </div>

        <footer class="auth-footer">Sentinel for LaraGram</footer>
    </main>

    <aside class="auth-side">
        <div class="auth-grid"></div>
        <div class="auth-side-inner">
            <img src="{{ $base }}/assets/img/favicon.svg?v={{ $version }}" alt="" width="112" height="112">
            <h2>Sentinel</h2>
            <p>The guardian at your bot's gate: updates, webhooks, Telegram API calls and exceptions, watched day and night.</p>
        </div>
    </aside>

    <button type="button" class="auth-theme" data-theme aria-label="Toggle dark mode">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
    </button>
</div>

<script>
    document.querySelectorAll('[data-tab]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('[data-tab]').forEach(function (other) { other.classList.toggle('active', other === tab); });
            document.querySelectorAll('[data-panel]').forEach(function (panel) {
                panel.hidden = panel.dataset.panel !== tab.dataset.tab;
                if (! panel.hidden) { var input = panel.querySelector('input:not([type=hidden])'); if (input) input.focus(); }
            });
        });
    });

    document.querySelectorAll('[data-reveal]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = button.parentNode.querySelector('input');
            input.type = input.type === 'password' ? 'text' : 'password';
        });
    });

    document.querySelector('[data-theme]').addEventListener('click', function () {
        var dark = document.documentElement.classList.toggle('dark');
        try { localStorage.setItem('sentinel.theme', dark ? 'dark' : 'light'); } catch (e) {}
    });

    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            var button = form.querySelector('.auth-submit');
            if (button) button.classList.add('loading');
        });
    });
</script>
</body>
</html>
