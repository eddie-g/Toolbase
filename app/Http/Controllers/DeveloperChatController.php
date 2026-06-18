<?php

namespace App\Http\Controllers;

use App\Services\DeveloperChatClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DeveloperChatController extends Controller
{
    public function __construct(
        private readonly DeveloperChatClient $client
    ) {
    }

    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required_without:messages|string|min:1',
            'system' => 'nullable|string',
            'temperature' => 'nullable|numeric|min:0|max:2',
            'messages' => 'nullable|array',
            'messages.*.role' => 'required_with:messages|string|in:user,assistant,system',
            'messages.*.content' => 'required_with:messages|string|min:1',
            'response_format' => 'nullable|array',
            'response_format.type' => 'required_with:response_format|string|in:json_object',
        ]);

        $messages = $validated['messages'] ?? [];

        if (empty($messages)) {
            if (!empty($validated['system'])) {
                $messages[] = ['role' => 'system', 'content' => $validated['system']];
            }
            if (!empty($validated['message'])) {
                $messages[] = ['role' => 'user', 'content' => $validated['message']];
            }
        }

        if (empty($messages)) {
            throw ValidationException::withMessages([
                'messages' => 'The messages field is required when message is not present.',
            ]);
        }

        try {
            $data = $this->client->chat(
                $messages,
                $validated['temperature'] ?? null,
                $validated['response_format'] ?? null
            );

            return response()->json([
                'reply' => $this->parseReply($data['reply']),
                'model' => $data['response']['model']
                    ?? $data['response']['candidates'][0]['modelVersion']
                    ?? config('services.gemini.model', 'gemini-2.5-flash-lite'),
                'usage' => $data['response']['usageMetadata'] ?? null,
                'raw' => $data['response'],
            ]);
        } catch (RequestException $e) {
            Log::error('Chat API Error', [
                'status' => $e->response?->status(),
                'body' => $e->response?->json(),
            ]);

            return response()->json([
                'message' => 'The chat service is currently unavailable.',
                'error' => $e->response?->json() ?? 'Unknown API Error',
            ], $e->response?->status() ?? 502);
        } catch (ConnectionException $e) {
            Log::error('Chat API Timeout', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'The chat service timed out. Please try again.',
            ], 504);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function geminiFileAnalyze(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|string|min:1',
            'messages' => 'nullable|array',
            'messages.*.role' => 'required_with:messages|string|in:user,assistant,system',
            'messages.*.content' => 'required_with:messages|string|min:1',
            'temperature' => 'nullable|numeric|min:0|max:2',
        ]);

        $requestedFile = $validated['file'];
        if (str_contains($requestedFile, '..') || str_starts_with($requestedFile, '/') || str_starts_with($requestedFile, '\\')) {
            return response()->json(['message' => 'Invalid file path.'], 422);
        }

        $basePath = storage_path('app/private');
        $filePath = $basePath . DIRECTORY_SEPARATOR . $requestedFile;

        if (!is_file($filePath)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $fileContents = file_get_contents($filePath);
        $decoded = json_decode($fileContents, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(['message' => 'File is not valid JSON.'], 422);
        }

        $messages = $validated['messages'] ?? [];
        if (empty($messages)) {
            return response()->json(['message' => 'messages array required for file analysis.'], 422);
        }

        $messages[] = [
            'role' => 'user',
            'content' => "JSON:\n{$fileContents}",
        ];

        try {
            $data = $this->client->chat(
                $messages,
                $validated['temperature'] ?? null
            );

            return response()->json([
                'reply' => $this->parseReply($data['reply']),
                'model' => $data['response']['model']
                    ?? $data['response']['candidates'][0]['modelVersion']
                    ?? config('services.gemini.model', 'gemini-2.5-flash-lite'),
                'usage' => $data['response']['usageMetadata'] ?? null,
                'raw' => $data['response'],
            ]);
        } catch (RequestException $e) {
            Log::error('Chat API Error', [
                'status' => $e->response?->status(),
                'body' => $e->response?->json(),
            ]);

            return response()->json([
                'message' => 'The chat service is currently unavailable.',
                'error' => $e->response?->json() ?? 'Unknown API Error',
            ], $e->response?->status() ?? 502);
        } catch (ConnectionException $e) {
            Log::error('Chat API Timeout', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'The chat service timed out. Please try again.',
            ], 504);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    // Wireframe stubs for additional endpoints from the original controller.

    public function tickerGeminiFinancials(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function tickerGeminiRatings(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function tickerUpcomingDates(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function geminiInsiderTrades(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function tickerSentimentReddit(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function tickerSentimentStockwits(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function tickerSenateTradesExternal(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function notableTrades(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function tickerGeminiSentiment(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function tickerNews(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function tickerNewsExternal(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function tickerSenateTrades(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    private function parseReply($reply): mixed
    {
        if (is_string($reply)) {
            $trimmed = trim($reply);

            if (str_starts_with($trimmed, '```')) {
                $trimmed = preg_replace('/^```[a-zA-Z]*\s*/', '', $trimmed);
                $trimmed = preg_replace('/```\s*$/', '', $trimmed);
                $trimmed = trim($trimmed);
            }

            $decoded = json_decode($trimmed, true);
            return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $reply;
        }

        return $reply;
    }
}
