<?php

namespace App\Http\Controllers;

use App\Support\SecretRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Where the editor reports what went wrong in the browser
 * (resources/js/edit-new-pdfjs/error-reporting.js): uncaught errors,
 * unhandled rejections and the failures the editor catches and carries on from.
 *
 * Anyone can post here, so it takes little, keeps little and never echoes
 * anything back: a capped body, a rate limit, one record per error and
 * visitor every five minutes. Document content is never part of a report.
 */
class ClientErrorController extends Controller
{
    private const KINDS = ['error', 'unhandledrejection', 'caught', 'resource'];

    public function store(Request $request): JsonResponse
    {
        $config = (array) config('observability.client_errors');
        if (! ($config['enabled'] ?? true)) {
            return response()->json(null, 204);
        }
        if (strlen((string) $request->getContent()) > ((int) ($config['max_body_kb'] ?? 16)) * 1024) {
            return response()->json(null, 413);
        }

        $report = [
            'kind' => in_array($request->input('kind'), self::KINDS, true) ? $request->input('kind') : 'error',
            'message' => $this->text($request->input('message'), 500),
            'stack' => $this->text($request->input('stack'), 4000),
            'source' => $this->text($request->input('source'), 300),
            'line' => $this->number($request->input('line')),
            'column' => $this->number($request->input('column')),
            'where' => $this->text($request->input('where'), 120),
            'page' => $this->text(strtok((string) $request->input('page'), '?') ?: '', 300),
            'document_id' => $this->number($request->input('document_id')),
            'editor_session' => $this->text($request->input('editor_session'), 80),
            'build' => $this->text($request->input('build'), 80),
            'user_agent' => $this->text($request->userAgent(), 300),
            'user_id' => Auth::guard('web')->id(),
            'admin_id' => Auth::guard('admin')->id(),
        ];
        if ($report['message'] === '') {
            return response()->json(null, 422);
        }

        $visitor = match (true) {
            $report['user_id'] !== null => 'user:'.$report['user_id'],
            $report['admin_id'] !== null => 'admin:'.$report['admin_id'],
            $request->hasSession() => $request->session()->getId(),
            default => (string) $request->ip(),
        };
        $fingerprint = sha1($report['kind'].'|'.$report['message'].'|'.Str::before($report['stack'], "\n").'|'.$visitor);
        if (! Cache::add('client-error:'.$fingerprint, true, (int) ($config['dedupe_seconds'] ?? 300))) {
            return response()->json(null, 204);
        }

        Log::warning('Client error', $report);
        $this->sendToSentry($report);

        return response()->json(null, 204);
    }

    private function sendToSentry(array $report): void
    {
        if (! app()->bound('sentry')) {
            return;
        }

        \Sentry\withScope(static function (\Sentry\State\Scope $scope) use ($report): void {
            $scope->setTag('source', 'editor');
            $scope->setTag('client_error_kind', (string) $report['kind']);
            foreach (['document_id', 'build', 'where'] as $tag) {
                if ($report[$tag] !== null && $report[$tag] !== '') {
                    $scope->setTag($tag, (string) $report[$tag]);
                }
            }
            $scope->setContext('client', array_filter($report, static fn ($value) => $value !== null && $value !== ''));
            // Group by what failed, not by this endpoint.
            $scope->setFingerprint(['client-error', (string) $report['kind'], (string) $report['message']]);
            \Sentry\captureMessage('[editor] '.$report['message'], \Sentry\Severity::error());
        });
    }

    private function text(mixed $value, int $limit): string
    {
        return is_scalar($value) ? Str::limit(SecretRedactor::redact(trim((string) $value)), $limit, '') : '';
    }

    private function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
