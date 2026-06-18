<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class DeveloperChatClient
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
        private readonly ?string $baseUrl = null
    ) {
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param float|null $temperature
     * @param array|null $responseFormat
     * @param array $options
     * @return array{reply: mixed, response: array}
     * @throws ConnectionException|RequestException
     */
    public function chat(array $messages, ?float $temperature = null, ?array $responseFormat = null, array $options = []): array
    {
        $apiKey = $this->apiKey ?? config('services.gemini.api_key');
        $model = $this->model ?? config('services.gemini.model', 'gemini-2.5-flash-lite');
        $baseUrl = rtrim($this->baseUrl ?? config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');

        $payload = [
            'contents' => $this->formatContents($messages),
        ];

        if ($temperature !== null) {
            $payload['generationConfig']['temperature'] = $temperature;
        }

        if ($responseFormat && isset($responseFormat['type']) && $responseFormat['type'] === 'json_object') {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
        }

        if (array_key_exists('thinking_budget', $options)) {
            $payload['generationConfig']['thinkingConfig']['thinkingBudget'] = (int) $options['thinking_budget'];
        }

        $timeout = $options['timeout'] ?? 120;

        // Pass the API key via the x-goog-api-key header rather than a `?key=`
        // query parameter, so the credential never appears in request URLs,
        // HTTP client exception messages, or logs.
        $response = Http::timeout($timeout)
            ->retry(2, 500)
            ->acceptJson()
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->post("{$baseUrl}/models/{$model}:generateContent", $payload)
            ->throw();

        $body = $response->json();
        $body['model'] = $body['model']
            ?? $body['candidates'][0]['modelVersion']
            ?? $model;

        return [
            'reply' => $this->extractReply($body),
            'response' => $body,
        ];
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<int, array{role: string, parts: array<int, array{text: string}>}>
     */
    private function formatContents(array $messages): array
    {
        $contents = [];
        $systemMessage = '';
        
        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            // Gemini uses 'model' instead of 'assistant' or 'system'
            // System messages should be prepended to the first user message
            if ($role === 'system') {
                $systemMessage = $content;
                continue;
            }
            
            // Convert assistant role to model
            if ($role === 'assistant') {
                $role = 'model';
            }
            
            // Prepend system message to first user message
            if ($role === 'user' && !empty($systemMessage)) {
                $content = $systemMessage . "\n\n" . $content;
                $systemMessage = '';
            }

            $contents[] = [
                'role' => $role,
                'parts' => [
                    ['text' => $content],
                ],
            ];
        }

        return $contents;
    }

    private function extractReply(array $response): mixed
    {
        return $response['candidates'][0]['content']['parts'][0]['text']
            ?? $response['candidates'][0]['content']['parts']
            ?? $response['candidates'][0]['content']
            ?? null;
    }

    /**
     * Generate an image using OpenAI's Images API.
     *
     * @param string $prompt
     * @param array $options
     * @return array{base64?: string, url?: string, response: array}
     * @throws ConnectionException|RequestException
     */
    public function generateImage(string $prompt, array $options = []): array
    {
        $apiKey = config('services.openai.api_key');
        $model = $options['model'] ?? config('services.openai.image_model', 'gpt-image-1');
        $baseUrl = rtrim(config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        if (!$apiKey) {
            throw new \RuntimeException('OpenAI API key is not configured. Set OPENAI_API_KEY in .env.');
        }

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'size' => $options['size'] ?? '1024x1024',
            'response_format' => $options['response_format'] ?? 'b64_json',
        ];

        if (isset($options['quality'])) {
            $payload['quality'] = $options['quality'];
        }

        $timeout = $options['timeout'] ?? 60;

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->withToken($apiKey)
            ->post("{$baseUrl}/images/generations", $payload)
            ->throw();

        $body = $response->json();
        $imageBase64 = $body['data'][0]['b64_json'] ?? null;
        $imageUrl = $body['data'][0]['url'] ?? null;

        return [
            'base64' => $imageBase64,
            'url' => $imageUrl,
            'response' => $body,
        ];
    }
}
