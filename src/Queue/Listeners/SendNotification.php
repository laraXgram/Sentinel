<?php

namespace LaraGram\Sentinel\Queue\Listeners;

use LaraGram\Sentinel\Alerts\Alerter;
use LaraGram\Sentinel\Queue\Lock;

class SendNotification
{
    /**
     * Handle the event.
     *
     * The notification is delivered as a Sentinel Telegram alert.
     *
     * @param  mixed  $event
     * @return void
     */
    public function handle($event)
    {
        $notification = $event->toNotification();

        $alerter = app(Alerter::class);

        if (! $alerter->wants('long_waits')) {
            return;
        }

        if (! app(Lock::class)->get('notification:'.$notification->signature(), 300)) {
            return;
        }

        $alerter->send('long_waits', $notification->signature(), $notification->title(), $notification->lines());
    }
}
