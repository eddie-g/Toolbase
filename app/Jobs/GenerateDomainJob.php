<?php

namespace App\Jobs;

use App\Models\AiDomainRequest;
use App\Models\AiPriceLog;
use App\Models\User;
use App\Services\DeveloperChatClient;
use App\Services\NamecheapClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class GenerateDomainJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 120;

    public int $userId;
    public int $domainRequestId;
    public array $params;

    public function __construct(int $userId, int $domainRequestId, array $params)
    {
        $this->userId          = $userId;
        $this->domainRequestId = $domainRequestId;
        $this->params          = $params;

        $this->onQueue('domain-generation');
    }

    public function handle(DeveloperChatClient $client): void
    {
        $domainRequest = AiDomainRequest::find($this->domainRequestId);
        $user          = User::find($this->userId);

        if (!$domainRequest || !$user) {
            Log::error('[GenerateDomainJob] Missing records', [
                'domain_request_id' => $this->domainRequestId,
                'user_id'           => $this->userId,
            ]);
            return;
        }

        $domainRequest->update(['status' => 'processing']);

        $prompt       = $this->params['prompt'];
        $tlds         = $this->params['tlds'];
        $maxNames     = $this->params['max_names'];
        $systemPrompt = $this->params['system_prompt'];

        try {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $prompt],
            ];

            $data = $client->chat(
                $messages,
                0.9,
                ['type' => 'json_object'],
                ['timeout' => 90],
            );

            $reply        = $data['reply'];
            $responseData = is_string($reply) ? json_decode($reply, true) : $reply;

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON from AI: ' . json_last_error_msg());
            }

            $domains = $responseData['domains'] ?? [];

            if (empty($domains)) {
                throw new \Exception('AI returned no domain names.');
            }

            // Log usage + cost
            $usageMetadata = $data['response']['usageMetadata'] ?? [];
            $inputTokens   = $usageMetadata['promptTokenCount']      ?? 0;
            $outputTokens  = $usageMetadata['candidatesTokenCount']   ?? 0;
            $totalTokens   = $usageMetadata['totalTokenCount'] ?? ($inputTokens + $outputTokens);
            $estimatedCost = ($inputTokens * 0.00000015) + ($outputTokens * 0.00000060);

            AiPriceLog::create([
                'session'        => null,
                'document_id'    => null,
                'user_email'     => $user->email,
                'request_type'   => 'domain_generation',
                'model_name'     => $data['response']['model'] ?? 'gemini-2.0-flash',
                'input_tokens'   => $inputTokens,
                'output_tokens'  => $outputTokens,
                'total_tokens'   => $totalTokens,
                'image_count'    => 0,
                'image_size'     => null,
                'cost_usd'       => null,
                'estimated_cost_usd' => $estimatedCost,
                'prompt_preview' => substr($prompt, 0, 255),
                'status'         => 'completed',
            ]);

            // Check domain availability
            $check = $this->checkAvailability($domains, $tlds);

            // Store all results on the record
            $domainRequest->update([
                'status'      => 'completed',
                'response'    => $responseData,
                'model'       => $data['response']['model'] ?? 'gemini-2.0-flash',
                'usage'       => $usageMetadata ?: null,
                'result_data' => json_encode([
                    'domains'   => $domains,
                    'results'   => $check['results'],
                    'usage'     => $usageMetadata ?: null,
                    'model'     => $data['response']['model'] ?? 'gemini-2.0-flash',
                    'error'     => $check['error'],
                ]),
            ]);
        } catch (\Throwable $e) {
            $isHttpError = $e instanceof \Illuminate\Http\Client\ConnectionException
                || $e instanceof \Illuminate\Http\Client\RequestException;

            Log::error('[GenerateDomainJob] Failed', [
                'domain_request_id' => $this->domainRequestId,
                'error'             => \App\Support\SecretRedactor::redact($e->getMessage()),
            ]);

            $domainRequest->update([
                'status'        => 'failed',
                'error_message' => $isHttpError
                    ? 'The AI service is temporarily unreachable. Please try again in a moment.'
                    : \App\Support\SecretRedactor::redact($e->getMessage()),
            ]);
        }
    }

    /**
     * Check domain availability — mirrors DomainSearchController::checkDomainAvailability().
     */
    private function checkAvailability(array $domains, array $tlds): array
    {
        if (config('services.domain_lookup') === 'namecheap') {
            return app(NamecheapClient::class)->checkAvailability($domains, $tlds);
        }

        return $this->checkWhois($domains, $tlds);
    }

    private function checkWhois(array $names, array $tlds): array
    {
        $allDomains = [];
        foreach ($names as $name) {
            $base = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', explode('.', $name)[0]));
            foreach ($tlds as $tld) {
                $allDomains[] = $base . '.' . $tld;
            }
        }

        $results         = [];
        $uncachedDomains = [];

        foreach ($allDomains as $domain) {
            $cached = Cache::get('domain:' . $domain);
            if ($cached !== null) {
                $results[] = $cached;
            } else {
                $uncachedDomains[] = $domain;
            }
        }

        if (empty($uncachedDomains)) {
            return ['results' => $results, 'error' => null];
        }

        $uncachedNames = array_unique(array_map(fn($d) => explode('.', $d)[0], $uncachedDomains));
        $scriptPath    = base_path('python/domain-search/check_domain_availability.py');

        $args   = ['python3', $scriptPath, '-t', ...$tlds, '--skip-http-check', '--', ...$uncachedNames];
        $result = Process::timeout(30)->run($args);

        if (!$result->successful()) {
            return ['results' => $results, 'error' => 'WHOIS check failed: ' . $result->errorOutput()];
        }

        $newResults = $this->parseOutput($result->output());
        foreach ($newResults as $r) {
            Cache::put('domain:' . $r['domain'], $r, now()->addHour());
            $results[] = $r;
        }

        return ['results' => $results, 'error' => null];
    }

    private function parseOutput(string $output): array
    {
        $results = [];
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $data = json_decode($line, true);
            if (!is_array($data) || !isset($data['domain'])) continue;

            $available = (bool) ($data['available'] ?? false);
            $forSale   = (bool) ($data['for_sale'] ?? false);

            $results[] = [
                'domain'    => $data['domain'],
                'available' => $available && !$forSale,
                'premium'   => false,
                'taken'     => !$available,
                'for_sale'  => $forSale,
                'tld'       => '.' . explode('.', $data['domain'])[1] ?? '',
                'error'     => $data['error'] ?? null,
            ];
        }
        return $results;
    }
}
