<?php

namespace LaraGram\Sentinel\Watchers;

use LaraGram\Conversation\Events;
use LaraGram\Sentinel\IncomingEntry;
use LaraGram\Sentinel\Sentinel;
use LaraGram\Sentinel\Support\Peek;
use LaraGram\Sentinel\Support\Redactor;
use LaraGram\Support\Str;

class ConversationWatcher extends Watcher
{
    /**
     * The conversation events and the step each one stands for.
     *
     * @var array<class-string, string>
     */
    protected const EVENTS = [
        Events\ConversationStarted::class => 'started',
        Events\QuestionAsked::class => 'asked',
        Events\AnswerReceived::class => 'answered',
        Events\AnswerInvalid::class => 'invalid',
        Events\QuestionSkipped::class => 'skipped',
        Events\BackRequested::class => 'back',
        Events\ConversationCompleted::class => 'completed',
        Events\ConversationCancelled::class => 'cancelled',
    ];

    /**
     * Register the watcher.
     *
     * @param  \LaraGram\Contracts\Foundation\Application  $app
     * @return void
     */
    public function register($app)
    {
        foreach (array_keys(static::EVENTS) as $event) {
            if (class_exists($event)) {
                $app['events']->listen($event, [$this, 'recordConversationEvent']);
            }
        }
    }

    /**
     * Record a conversation event.
     *
     * @param  object  $event
     * @return void
     */
    public function recordConversationEvent($event)
    {
        if (! Sentinel::isRecording()) {
            return;
        }

        $step = static::EVENTS[get_class($event)];
        $name = $event->name ?? 'unknown';
        $question = $event->question ?? null;

        Sentinel::recordConversation(IncomingEntry::make(array_filter([
            'name' => $name,
            'step' => $step,
            'question' => $question ? $this->question($question) : null,
            'answer' => isset($event->answer) ? $this->answer($event->answer) : null,
            'errors' => $event->errors ?? null,
            'attempt' => $event->attempt ?? null,
            'reason' => $event->reason ?? null,
            'answers' => isset($event->answers) ? $this->answers($event->answers) : null,
        ], fn ($value) => $value !== null))->withFamilyHash(md5('conversation:'.$name))->tags([
            'conversation:'.$name,
            'step:'.$step,
        ]));

        if (Sentinel::$simulation === null) {
            Sentinel::metric('conversation', json_encode([$name, $step]), null, ['count']);
        }
    }

    /**
     * Describe a question.
     *
     * @param  object  $question
     * @return array
     */
    protected function question(object $question): array
    {
        $prompt = Peek::property($question, 'prompt');

        return array_filter([
            'name' => Peek::property($question, 'name'),
            'type' => Peek::property($question, 'type'),
            'prompt' => is_string($prompt) ? Str::limit($prompt, 300) : ($prompt ? 'Closure' : null),
            'rules' => is_string($rules = Peek::property($question, 'rules')) || is_array($rules) ? $rules : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Describe an answer.
     *
     * @param  object  $answer
     * @return array
     */
    protected function answer(object $answer): array
    {
        return array_filter([
            'key' => rescue(fn () => $answer->key(), null, false),
            'type' => rescue(fn () => $answer->type(), null, false),
            'text' => rescue(fn () => $answer->text(), null, false),
            'data' => rescue(fn () => $answer->data(), null, false),
            'skipped' => rescue(fn () => $answer->isSkipped(), null, false) ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Describe the collected answers.
     *
     * @param  object  $answers
     * @return array|null
     */
    protected function answers(object $answers): ?array
    {
        $values = rescue(fn () => method_exists($answers, 'toArray') ? $answers->toArray() : (method_exists($answers, 'all') ? $answers->all() : null), null, false);

        return is_array($values) ? Redactor::redact(json_decode(json_encode($values), true) ?? [], 'fields') : null;
    }
}
