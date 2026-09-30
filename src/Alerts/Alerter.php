<?php

namespace LaraGram\Sentinel\Alerts;

use LaraGram\Sentinel\Contracts\EntriesRepository;
use LaraGram\Sentinel\EntryType;
use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Sentinel\Telegram\BotInspector;
use LaraGram\Support\Collection;
use LaraGram\Support\Str;
use Throwable;

class Alerter
{
    /**
     * Create a new alerter.
     *
     * @param  \LaraGram\Sentinel\Telegram\BotInspector  $bots
     * @param  \LaraGram\Sentinel\Contracts\EntriesRepository  $entries
     * @return void
     */
    public function __construct(
        protected BotInspector $bots,
        protected EntriesRepository $entries,
    ) {
    }

    /**
     * Determine if alerts are enabled.
     *
     * @return bool
     */
    public function enabled(): bool
    {
        return (bool) config('sentinel.alerts.enabled') && $this->chatIds() !== [] && $this->connection() !== null;
    }

    /**
     * Determine if alerts of a kind are enabled.
     *
     * @param  string  $kind
     * @return bool
     */
    public function wants(string $kind): bool
    {
        return $this->enabled() && (bool) config("sentinel.alerts.on.{$kind}", true);
    }

    /**
     * Inspect a stored batch and alert about what went wrong.
     *
     * @param  \LaraGram\Support\Collection<int, \LaraGram\Sentinel\IncomingEntry>  $entries
     * @param  string  $batchId
     * @return void
     */
    public function inspect(Collection $entries, string $batchId): void
    {
        if (! $this->enabled() || Sentinel::$simulation !== null) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry->isException() && $this->wants('exceptions')) {
                $this->send('exception', $entry->familyHash(), '🚨 Exception', [
                    '<code>'.$this->e($entry->content['class']).'</code>',
                    $this->e(Str::limit($entry->content['message'] ?? '', 500)),
                    '📍 <code>'.$this->e(($entry->content['file'] ?? '').':'.($entry->content['line'] ?? '')).'</code>',
                ], $entry);
            }

            if ($entry->type === EntryType::API_CALL && ! empty($entry->content['retry_after']) && $this->wants('flood_waits')) {
                $this->send('flood', ($entry->connection ?? '').$entry->content['method'], '🐢 Flood wait', [
                    'Telegram asked the bot to wait <b>'.(int) $entry->content['retry_after'].'s</b> before calling <code>'.$this->e($entry->content['method']).'</code> again.',
                ], $entry);
            }

            if ($entry->isFailedJob() && $this->wants('failed_jobs')) {
                $this->send('job', $entry->content['name'] ?? 'job', '⚙️ Job failed', [
                    '<code>'.$this->e($entry->content['name'] ?? 'Unknown').'</code>',
                    $this->e(Str::limit($entry->content['exception']['message'] ?? '', 400)),
                ], $entry);
            }
        }
    }

    /**
     * Send an alert, unless the same alert was sent recently.
     *
     * @param  string  $kind
     * @param  string  $key
     * @param  string  $title
     * @param  array<int, string>  $lines  HTML lines of the message body.
     * @param  \LaraGram\Sentinel\IncomingEntry|null  $entry
     * @return bool
     */
    public function send(string $kind, string $key, string $title, array $lines, ?IncomingEntry $entry = null): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $throttle = (int) config('sentinel.alerts.throttle', 600);

        try {
            if ($throttle > 0 && ! cache()->add('sentinel:alert:'.md5($kind.'|'.$key), time(), $throttle)) {
                return false;
            }
        } catch (Throwable) {
            //
        }

        $bot = $entry?->connection ? ' · '.$this->e($entry->connection) : '';

        $text = "<b>{$title}</b>{$bot}\n\n".implode("\n", array_filter($lines));

        if ($link = $this->link($entry)) {
            $text .= "\n\n<a href=\"{$this->e($link)}\">Open in Sentinel</a>";
        }

        $delivered = 0;

        foreach ($this->chatIds() as $chatId) {
            $response = $this->bots->call($this->connection(), 'sendMessage', [
                'chat_id' => $chatId,
                'text' => Str::limit($text, 4000),
                'parse_mode' => 'HTML',
                'link_preview_options' => ['is_disabled' => true],
            ]);

            $delivered += ($response['ok'] ?? false) ? 1 : 0;
        }

        rescue(fn () => $this->entries->store(collect([
            IncomingEntry::make([
                'kind' => $kind,
                'title' => strip_tags($title),
                'message' => strip_tags(implode("\n", $lines)),
                'chat_ids' => $this->chatIds(),
                'delivered' => $delivered,
                'entry' => $entry?->uuid,
            ])->type(EntryType::ALERT)
                ->batchId($entry?->batchId ?? (string) Str::orderedUuid())
                ->connection($entry?->connection)
                ->tags(['alert:'.$kind]),
        ])), report: false);

        return $delivered > 0;
    }

    /**
     * Get the dashboard link of an entry.
     *
     * @param  \LaraGram\Sentinel\IncomingEntry|null  $entry
     * @return string|null
     */
    protected function link(?IncomingEntry $entry): ?string
    {
        $base = rtrim((string) config('app.url'), '/');

        if ($base === '' || Str::contains($base, ['localhost', '127.0.0.1'])) {
            return null;
        }

        $path = $base.'/'.trim(config('sentinel.path', 'sentinel'), '/');

        return $entry ? $path.'#/entries/'.$entry->type.'/'.$entry->uuid : $path;
    }

    /**
     * Get the chats alerts are sent to.
     *
     * @return array<int, string>
     */
    protected function chatIds(): array
    {
        return array_values(array_filter(array_map('trim', (array) config('sentinel.alerts.chat_ids', []))));
    }

    /**
     * Get the bot connection alerts are sent with.
     *
     * @return string|null
     */
    protected function connection(): ?string
    {
        $connection = config('sentinel.alerts.connection') ?: config('bot.default');

        if (! $connection || $connection === 'auto') {
            $connection = array_key_first((array) config('bot.connections', []));
        }

        return $connection ?: null;
    }

    /**
     * Escape text for Telegram HTML.
     *
     * @param  string  $value
     * @return string
     */
    protected function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
