<?php

namespace LaraGram\Sentinel\Http\Controllers;

use LaraGram\Http\Request;
use LaraGram\Routing\Controller;
use LaraGram\Sentinel\Auth\Authenticator;
use LaraGram\Sentinel\Sentinel;

class LoginController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param  \LaraGram\Sentinel\Auth\Authenticator  $auth
     * @return void
     */
    public function __construct(
        protected Authenticator $auth,
    ) {
    }

    /**
     * Show the login screen.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return \LaraGram\Http\Response
     */
    public function show(Request $request)
    {
        if (Sentinel::check($request)) {
            return redirect()->route('sentinel.dashboard');
        }

        $state = (array) $request->session()->get('sentinel_login', []);
        $methods = $this->auth->methods();

        return response()->view('sentinel::login', [
            'base' => '/'.trim(config('sentinel.path', 'sentinel'), '/'),
            'version' => DashboardController::assetsVersion(),
            'token' => $request->session()->token(),
            'methods' => $methods,
            'method' => in_array($state['method'] ?? null, $methods, true) ? $state['method'] : ($methods[0] ?? null),
            'step' => $state['step'] ?? 'start',
            'telegramId' => $state['id'] ?? '',
            'username' => $state['username'] ?? '',
            'error' => $state['error'] ?? null,
            'notice' => $state['notice'] ?? null,
            'app' => config('app.name', 'LaraGram'),
            'bot' => config('bot.connections.'.config('bot.default').'.username'),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Send a one-time code to a Telegram admin.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return \LaraGram\Http\RedirectResponse
     */
    public function sendCode(Request $request)
    {
        $result = $this->auth->sendCode($request, (string) $request->input('telegram_id'));

        return $this->back($request, $result['ok'] ? [
            'method' => 'telegram',
            'step' => 'code',
            'id' => $result['id'],
            'notice' => 'If this account is an admin, the bot just sent you a 6-digit code.',
        ] : [
            'method' => 'telegram',
            'id' => (string) $request->input('telegram_id'),
            'error' => $result['error'],
        ]);
    }

    /**
     * Log in with a one-time code.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return \LaraGram\Http\RedirectResponse
     */
    public function verifyCode(Request $request)
    {
        $id = (string) $request->input('telegram_id');
        $result = $this->auth->verifyCode($request, $id, (string) $request->input('code'));

        if ($result['ok']) {
            return redirect()->route('sentinel.dashboard');
        }

        return $this->back($request, ['method' => 'telegram', 'step' => 'code', 'id' => $id, 'error' => $result['error']]);
    }

    /**
     * Log in with the username and password.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return \LaraGram\Http\RedirectResponse
     */
    public function password(Request $request)
    {
        $username = (string) $request->input('username');
        $result = $this->auth->attemptPassword($request, $username, (string) $request->input('password'));

        if ($result['ok']) {
            return redirect()->route('sentinel.dashboard');
        }

        return $this->back($request, ['method' => 'password', 'username' => $username, 'error' => $result['error']]);
    }

    /**
     * Log out.
     *
     * @param  \LaraGram\Http\Request  $request
     * @return \LaraGram\Http\RedirectResponse
     */
    public function logout(Request $request)
    {
        $this->auth->logout($request);

        return $this->back($request, ['notice' => 'You have been logged out.']);
    }

    /**
     * Go back to the login screen with the given state.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  array  $state
     * @return \LaraGram\Http\RedirectResponse
     */
    protected function back(Request $request, array $state)
    {
        $request->session()->flash('sentinel_login', $state);

        return redirect()->route('sentinel.login');
    }
}
