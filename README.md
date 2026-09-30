<p align="center"><img src="resources/assets/img/favicon.svg" width="96" alt="Sentinel"></p>

<h1 align="center">LaraGram Sentinel</h1>

<p align="center">Watch over your Telegram bot: updates, webhooks, Bot API calls, exceptions and performance — in one dashboard.</p>

---

Sentinel combines the two things you need while running a bot:

- **A debugger** (in the spirit of Telescope) that records every update your bot receives together with everything that happened while handling it: the Telegram API calls it made, database queries, logs, cache operations, conversation steps and exceptions.
- **A monitor** (in the spirit of Pulse) that keeps cheap, time-bucketed metrics: traffic by update type, active and new users, busiest commands, slowest handlers, API latency, error rates and flood waits.

On top of that it knows Telegram:

| Feature | What it does |
| --- | --- |
| **Webhook inspector** | Live `getWebhookInfo` for every bot connection with a health score, delivery errors, pending updates, IP, allowed updates — and set / delete / drop pending from the dashboard. |
| **Commands check** | Compares the commands registered with BotFather to your listeners: commands users see but nothing answers, and handlers missing from the menu. |
| **Chat preview** | Every update is replayed as a Telegram chat: what the user sent and what the bot answered, including inline keyboards and formatting. |
| **Playground** | Compose a fake update (text, command, button press, inline query, location, raw JSON…) and run it through your real bot code. In *dry run* mode every API call is answered locally, so nobody receives anything. |
| **Replay** | Run any recorded update again — dry or live — to reproduce a bug. |
| **Users & chats** | Who talks to your bot, who is new, their languages, and who **blocked** the bot. |
| **Listeners** | Every registered listen with hit counts and timings, plus the updates no listen matched. |
| **Conversations** | Each conversation as a funnel: started, answered, invalid answers, completed, cancelled. |
| **API calls** | Volume, latency, errors grouped by method/code, and **flood waits** (`retry_after`) per method. |
| **Telegram alerts** | Get a message when an exception is thrown, the webhook fails, updates pile up, a job fails or Telegram flood-limits the bot. |
| **Servers & network** | CPU, memory and disk of each server, Telegram API latency, and the health of the proxy pool. |

Bot tokens are always masked, and sensitive parameters, headers and fields are redacted before anything is stored.

## Requirements

- PHP 8.3+
- `laraxgram/core` 4.x with the `ApiCallSending` / `ApiCallCompleted` events (used to record Bot API calls)
- A database supported by LaraGram (MySQL, MariaDB, PostgreSQL or SQLite)

## Installation

```bash
composer require laraxgram/sentinel

php laragram sentinel:install
php laragram migrate
```

`sentinel:install` publishes `config/sentinel.php`, the migration and `App\Providers\SentinelServiceProvider`, and registers the provider.

Open **`/sentinel`** in your browser.

## Logging in

Sentinel has its own login, separate from your application's users. Enable one or both methods in `.env`:

```dotenv
# Telegram: these admins type their user ID (or @username) and the bot sends them a one-time code
SENTINEL_ADMINS=123456789,987654321
SENTINEL_AUTH_CONNECTION=bot          # the bot that sends the codes (defaults to bot.default)

# Username and password (plain text, or a bcrypt/argon hash)
SENTINEL_USERNAME=admin
SENTINEL_PASSWORD=choose-a-long-password

SENTINEL_SESSION_LIFETIME=720         # minutes
SENTINEL_LOGIN_NOTIFY=true            # tell the admins on Telegram about every login
```

- As soon as one method is configured, **every** visit needs a login, locally too.
- Codes expire after 5 minutes and die after 5 wrong tries.
- Each IP is limited to 5 attempts per minute.
- The login form answers the same way for unknown IDs, so it can't be used to find out who the admins are.
- Every login is recorded under **Alerts**, and the admins get a Telegram message about it.
- Log out from the user menu in the top-right corner.

With no method configured, the dashboard stays open in the `local` environment only.

Users of your own application can also be let in through the `viewSentinel` gate in `App\Providers\SentinelServiceProvider`, when they are logged in through the `web` guard:

```php
protected function gate(): void
{
    Gate::define('viewSentinel', function ($user = null) {
        return in_array($user?->email, [
            'you@example.com',
        ]);
    });
}
```

For a completely custom rule, register your own callback:

```php
Sentinel::auth(fn ($request) => in_array($request->ip(), ['203.0.113.7']));
```

## Keep it running

Two commands keep Sentinel useful in production:

```bash
# Snapshot every webhook, the proxy pool and this server; sends webhook alerts.
php laragram sentinel:check

# Delete entries older than the retention window (schedule it daily).
php laragram sentinel:prune
```

Run `sentinel:check` under Supervisor (or any process manager) on each server. Without it the dashboard still works, but webhook history, server charts and webhook alerts need it.

```php
// routes/console.php
Schedule::command('sentinel:prune')->daily();
```

Other commands: `sentinel:pause`, `sentinel:resume`, `sentinel:clear [--metrics]`.

## Configuration

Everything lives in `config/sentinel.php`. The most useful settings:

```dotenv
SENTINEL_ENABLED=true
SENTINEL_PATH=sentinel
SENTINEL_DB_CONNECTION=            # keep monitoring writes away from your bot's database

SENTINEL_UPDATE_SAMPLE_RATE=1      # 0.1 keeps 10% of update entries; metrics stay complete

SENTINEL_PLAYGROUND=true
SENTINEL_PLAYGROUND_LIVE=false     # allow the playground and replays to really send API calls
SENTINEL_WEBHOOK_CHANGES=true      # allow setting / deleting webhooks from the dashboard

SENTINEL_ALERTS=true
SENTINEL_ALERTS_CHAT_IDS=123456789,-1001234567890
SENTINEL_ALERTS_CONNECTION=bot
```

Each watcher can be switched off or tuned under `watchers`. When sampling, exceptions, failed API calls and failed jobs are always kept.

## Filtering, tagging and redaction

```php
use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Sentinel\Sentinel;

// Only keep what matters in production.
Sentinel::filter(fn (IncomingEntry $entry) =>
    $entry->isException() || $entry->isFailedApiCall() || $entry->type === 'update'
);

// Add your own tags; search them in the dashboard.
Sentinel::tag(fn (IncomingEntry $entry) => $entry->type === 'update' ? ['plan:'.auth()->user()?->plan] : []);

// Never store these API parameters.
Sentinel::hide('parameters', ['phone_number']);
```

Every update is tagged automatically — `chat:<id>`, `user:<id>`, `connection:<name>`, `update:<type>`, `kind:<kind>`, `command:/start`, `unhandled`, `failed`, `slow` — and the API calls, logs and exceptions it caused carry the same `chat:` and `user:` tags. Type `user:123456` in the search box to see everything one user did.

*Monitored tags* (Settings page) are always recorded, even when a filter would drop them — handy to debug one user in production.

## Custom metrics

Record your own numbers and chart them next to the built-in ones:

```php
Sentinel::metric('payment', $plan, $amount, ['count', 'sum']);
```

## How it works

Each webhook delivery is handled in its own PHP process. Sentinel buffers what happens while the update is handled and writes it in a single batch when the process terminates, so it adds almost nothing to your bot's response time. Long-running processes (queue workers, Surge) flush after every job or request.

Bot API calls are observed through the `LaraGram\Request\Events\ApiCallCompleted` event. The playground runs updates in a separate process with `Request::interceptUsing()` answering every call in dry-run mode.

## License

Sentinel is open-sourced software licensed under the [MIT license](LICENSE.md).
