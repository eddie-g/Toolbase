<?php

namespace App\Observability;

use App\Support\SecretRedactor;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Last stop before an event leaves for Sentry (config sentry.before_send):
 * an HTTP client can put a request URL with an API key into an exception
 * message, and that must not leave in it. Named as a callable array so the
 * configuration can still be cached.
 */
class SentryScrubber
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        if ($event->getMessage() !== null) {
            $event->setMessage(SecretRedactor::redact($event->getMessage()), $event->getMessageParams(), SecretRedactor::redact($event->getMessageFormatted()));
        }
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(SecretRedactor::redact($exception->getValue()));
        }

        return $event;
    }
}
