<?php

namespace LaraGram\Sentinel\Queue\Notifications;

use LaraGram\Sentinel\Queue\Contracts\LongWaitDetectedNotification;

class LongWaitDetected implements LongWaitDetectedNotification
{
    /**
     * The queue connection name.
     *
     * @var string
     */
    public $longWaitConnection;

    /**
     * The queue name.
     *
     * @var string
     */
    public $longWaitQueue;

    /**
     * The wait time in seconds.
     *
     * @var int
     */
    public $seconds;

    /**
     * Create a new notification instance.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @param  int  $seconds
     * @return void
     */
    public function __construct($connection, $queue, $seconds)
    {
        $this->longWaitQueue = $queue;
        $this->seconds = $seconds;
        $this->longWaitConnection = $connection;
    }

    /**
     * Get the title of the Telegram alert.
     *
     * @return string
     */
    public function title()
    {
        return '⏳ Long queue wait';
    }

    /**
     * Get the HTML lines of the Telegram alert.
     *
     * @return array<int, string>
     */
    public function lines()
    {
        return [
            sprintf(
                'The <code>%s</code> queue on the <code>%s</code> connection has a wait time of <b>%s seconds</b>.',
                e($this->longWaitQueue),
                e($this->longWaitConnection),
                $this->seconds,
            ),
            'App: '.e((string) config('app.name')),
        ];
    }

    /**
     * The unique signature of the notification.
     *
     * @return string
     */
    public function signature()
    {
        return md5($this->longWaitConnection.$this->longWaitQueue);
    }
}
