<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Many controllers answer a failure with the Python output or a stack trace
 * in the JSON body. With APP_DEBUG off those are taken out of the response,
 * logged under the request id, and the response carries that id instead.
 */
class ScrubErrorDetails
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || config('app.debug')) {
            return $response;
        }

        $data = $response->getData(true);
        if (! is_array($data) || ! $this->isFailure($response, $data)) {
            return $response;
        }

        $removed = [];
        foreach ((array) config('observability.scrub_response_keys') as $key) {
            if (array_key_exists($key, $data)) {
                $removed[$key] = $data[$key];
                unset($data[$key]);
            }
        }
        $data['request_id'] ??= $request->attributes->get('request_id');

        if ($removed !== []) {
            Log::warning('Error details kept out of a response', [
                'route' => $request->route()?->getName() ?? $request->path(),
                'status' => $response->getStatusCode(),
                'message' => $data['message'] ?? $data['error'] ?? null,
                'details' => array_map(
                    static fn ($value) => Str::limit(is_string($value) ? $value : (string) json_encode($value), 4000),
                    $removed
                ),
            ]);
        }

        return $response->setData($data);
    }

    private function isFailure(JsonResponse $response, array $data): bool
    {
        return $response->getStatusCode() >= 400 || (array_key_exists('success', $data) && $data['success'] === false);
    }
}
