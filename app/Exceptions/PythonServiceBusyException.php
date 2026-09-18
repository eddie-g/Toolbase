<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when every Python slot on this server is taken for longer than
 * the configured wait. Rendered as 503 with Retry-After for web requests;
 * a queued job lets it bubble so the job retries with backoff.
 */
class PythonServiceBusyException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds = 5, string $message = 'The PDF service is busy. Please try again in a moment.')
    {
        parent::__construct($message);
    }
}
