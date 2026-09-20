<?php

namespace App\Observability;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

/**
 * What an error report needs to be acted on: which document, which account,
 * which route or job, which request. Added to the log line of every reported
 * exception and, when Sentry is on, to the event as tags.
 */
class ErrorContext
{
    /** @return array<string, int|string> */
    public static function current(): array
    {
        $context = [];

        if (! app()->runningInConsole() || app()->runningUnitTests()) {
            $request = request();
            $route = $request->route();
            if ($route !== null) {
                $context['route'] = (string) ($route->getName() ?? $route->uri());
                $document = $route->parameter('document');
                $documentId = is_object($document) ? ($document->id ?? null) : $document;
                if (is_numeric($documentId)) {
                    $context['document_id'] = (int) $documentId;
                }
            }
            try {
                foreach (['user_id' => 'web', 'admin_id' => 'admin'] as $key => $guard) {
                    if (($id = Auth::guard($guard)->id()) !== null) {
                        $context[$key] = (int) $id;
                    }
                }
            } catch (\Throwable) {
                // No session yet: the error is from before authentication.
            }
        }

        if (is_string($requestId = Context::get('request_id'))) {
            $context['request_id'] = $requestId;
        }
        foreach (['job', 'job_document_id'] as $key) {
            if (($value = Context::getHidden($key)) !== null) {
                $context[$key === 'job_document_id' ? 'document_id' : $key] = $value;
            }
        }

        return $context;
    }

    /** Called before Sentry captures an exception. */
    public static function tagSentryScope(): void
    {
        if (! app()->bound('sentry')) {
            return;
        }

        $context = self::current();
        \Sentry\configureScope(static function (\Sentry\State\Scope $scope) use ($context): void {
            foreach ($context as $key => $value) {
                $scope->setTag($key, (string) $value);
            }
            // The id only: never the e-mail address or the IP.
            if (isset($context['user_id'])) {
                $scope->setUser(['id' => 'user:'.$context['user_id']]);
            } elseif (isset($context['admin_id'])) {
                $scope->setUser(['id' => 'admin:'.$context['admin_id']]);
            }
        });
    }

    /** A queued job says which job it is and, when it has one, which document. */
    public static function rememberJob(JobProcessing $event): void
    {
        Context::addHidden('job', $event->job->resolveName());
        Context::forgetHidden('job_document_id');

        $command = $event->job->payload()['data']['command'] ?? null;
        if (is_string($command) && preg_match('/"documentId";i:(\d+)/', $command, $match) === 1) {
            Context::addHidden('job_document_id', (int) $match[1]);
        }
    }
}
