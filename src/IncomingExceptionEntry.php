<?php

namespace LaraGram\Sentinel;

use Throwable;

class IncomingExceptionEntry extends IncomingEntry
{
    /**
     * The underlying exception instance.
     *
     * @var \Throwable
     */
    public $exception;

    /**
     * Create a new incoming entry instance.
     *
     * @param  \Throwable  $exception
     * @param  array  $content
     * @return void
     */
    public function __construct(Throwable $exception, array $content)
    {
        $this->exception = $exception;

        parent::__construct($content);
    }

    /**
     * Determine if the incoming entry is an exception.
     *
     * @return bool
     */
    public function isException()
    {
        return true;
    }

    /**
     * Calculate the family look-up hash for the incoming entry.
     *
     * @return string
     */
    public function familyHash()
    {
        return md5(get_class($this->exception).'|'.$this->exception->getFile().'|'.$this->exception->getLine());
    }
}
