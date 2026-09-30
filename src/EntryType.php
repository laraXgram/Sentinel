<?php

namespace LaraGram\Sentinel;

class EntryType
{
    public const UPDATE = 'update';
    public const API_CALL = 'api_call';
    public const CONVERSATION = 'conversation';
    public const EXCEPTION = 'exception';
    public const LOG = 'log';
    public const QUERY = 'query';
    public const JOB = 'job';
    public const CACHE = 'cache';
    public const COMMAND = 'command';
    public const SCHEDULED_TASK = 'schedule';
    public const REQUEST = 'request';
    public const EVENT = 'event';
    public const ALERT = 'alert';

    /**
     * Get every entry type.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::UPDATE, self::API_CALL, self::CONVERSATION, self::EXCEPTION,
            self::LOG, self::QUERY, self::JOB, self::CACHE, self::COMMAND,
            self::SCHEDULED_TASK, self::REQUEST, self::EVENT, self::ALERT,
        ];
    }
}
