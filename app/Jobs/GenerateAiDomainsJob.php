<?php

namespace App\Jobs;

use App\Models\AiDomainRequest;
use App\Services\DeveloperChatClient;
use App\Services\NamecheapClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class GenerateAiDomainsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 150;
    public bool $failOnTimeout = true;

    public function __construct(
        public string $jobId,
        public string $prompt,
        public array $tlds,
        public string $promptModifier = 'none',
        public array $excluded = [],
        public ?int $userId = null,
        public ?int $domainRequestId = null
    ) {
        $this->onQueue('domain-generation');
    }

    public function handle(DeveloperChatClient $client): void
    {
        $cacheKey = $this->cacheKey();
        $existing = Cache::get($cacheKey, []);

        Cache::put($cacheKey, array_merge($existing, [
            'status' => 'processing',
            'updated_at' => now()->toISOString(),
        ]), now()->addMinutes(30));

        $domainRequest = $this->resolveDomainRequest();
        if ($domainRequest) {
            $domainRequest->status = 'processing';
            $domainRequest->save();
        }

        try {
            $tldCount = count($this->tlds);
            $maxNames = $tldCount > 0 ? (int) floor(50 / $tldCount) : 20;
            $maxNames = max(5, min($maxNames, 50));

            $systemPrompt = "You are a creative domain name generator. Generate {$maxNames} unique, brandable domain names based on the user's request. Return ONLY a JSON object with a 'domains' array containing domain name strings (without TLDs).\n\nSTRICT RULES — every name MUST follow all of these:\n1. A valid domain label: no spaces and no underscores. Hyphens are allowed only when intentional for branding (e.g. \"e-flux\").\n2. If the user asks for a specific word count or a combination of words, satisfy that as visible root/concept components in the domain label. Example: a 3-word request should look like three short combined roots, such as \"realmquestforge\" or \"guildraidnexus\", not a one-word invention.\n3. NO common English stop words, articles, conjunctions, or prepositions anywhere in the name. Forbidden words: the, a, an, and, or, but, of, for, in, on, at, to, with, this, that, these, those, it, is, be, by, as, up, do.\n4. Short: normally 4-15 characters total; allow up to 30 characters when the user explicitly asks for multi-word combinations.\n5. Memorable and brandable — sounds like a real product or company name.\n6. Easy to spell and pronounce.\n7. Directly related to or evocative of the user's prompt.\n8. Creative, modern, and professional — prefer meaningful blends over generic dictionary phrases unless the user explicitly asks for word combinations.\n\nGood examples: techflow, cloudnova, pixelforge, datazen, codecraft, realmquestforge, guildraidnexus, lorewararena\nBad examples: thedesign, thisapp, andmore, forall, topmatch (contain stop words or are generic phrases)\n\nExample response format:\n{\"domains\": [\"techflow\", \"cloudnova\", \"pixelforge\", \"realmquestforge\", \"guildraidnexus\"]}";

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $this->buildPrompt()],
            ];

            $data = $client->chat(
                $messages,
                0.9,
                ['type' => 'json_object'],
                ['timeout' => 90, 'thinking_budget' => 0],
            );

            $reply = $data['reply'];
            $responseData = is_string($reply) ? json_decode($reply, true) : $reply;

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response from AI: ' . json_last_error_msg());
            }

            $domains = $this->sanitizeDomains($responseData['domains'] ?? []);

            if (empty($domains)) {
                throw new \RuntimeException('No domain names could be generated.');
            }

            $usageMetadata = $data['response']['usageMetadata'] ?? [];
            $check = $this->checkDomainAvailability($domains, $this->tlds);

            Cache::put($cacheKey, array_merge($existing, [
                'status' => 'completed',
                'domains' => $domains,
                'results' => $check['results'],
                'usage' => $usageMetadata ?: null,
                'model' => $data['response']['model'] ?? config('services.gemini.model', 'gemini-2.5-flash-lite'),
                'error' => $check['error'],
                'done' => true,
                'updated_at' => now()->toISOString(),
            ]), now()->addMinutes(30));

            if ($domainRequest) {
                $domainRequest->status = 'completed';
                $domainRequest->response = $responseData;
                $domainRequest->model = $data['response']['model'] ?? config('services.gemini.model', 'gemini-2.5-flash-lite');
                $domainRequest->usage = $usageMetadata ?: null;
                $domainRequest->result_data = json_encode([
                    'domains' => $domains,
                    'results' => $check['results'],
                    'usage' => $usageMetadata ?: null,
                    'model' => $data['response']['model'] ?? config('services.gemini.model', 'gemini-2.5-flash-lite'),
                    'error' => $check['error'],
                    'job_id' => $this->jobId,
                ]);
                $domainRequest->error_message = $check['error'];
                $domainRequest->save();
            }
        } catch (\Throwable $e) {
            $this->markFailed($e, $domainRequest);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $domainRequest = $this->domainRequestId
            ? AiDomainRequest::find($this->domainRequestId)
            : null;

        $this->markFailed($exception, $domainRequest);
    }

    private function resolveDomainRequest(): ?AiDomainRequest
    {
        if ($this->domainRequestId) {
            return AiDomainRequest::find($this->domainRequestId);
        }

        if (!$this->userId) {
            return null;
        }

        $domainRequest = AiDomainRequest::create([
            'user_id' => $this->userId,
            'prompt' => $this->prompt,
        ]);
        $domainRequest->tlds = $this->tlds;
        $domainRequest->save();

        return $domainRequest;
    }

    private function cacheKey(): string
    {
        return 'ai-domain-job:' . $this->jobId;
    }

    private function markFailed(\Throwable $e, ?AiDomainRequest $domainRequest = null): void
    {
        $cacheKey = $this->cacheKey();
        $existing = Cache::get($cacheKey, []);
        $message = $this->failureMessage($e);

        Cache::put($cacheKey, array_merge($existing, [
            'status' => 'failed',
            'error' => $message,
            'done' => true,
            'updated_at' => now()->toISOString(),
        ]), now()->addMinutes(30));

        if ($domainRequest) {
            $domainRequest->status = 'failed';
            $domainRequest->error_message = $message;
            $domainRequest->save();
        }

        Log::warning('[GenerateAiDomainsJob] Failed', [
            'job_id' => $this->jobId,
            'domain_request_id' => $this->domainRequestId,
            'exception' => get_class($e),
            'message' => \App\Support\SecretRedactor::redact($e->getMessage()),
        ]);
    }

    private function failureMessage(\Throwable $e): string
    {
        if ($e instanceof \Illuminate\Queue\TimeoutExceededException) {
            return 'AI domain generation timed out before completion. Please try again.';
        }

        // Never surface raw HTTP/connection exception messages to users: they can
        // contain request URLs, headers, or other internal details. Show a safe,
        // generic message instead.
        if ($e instanceof \Illuminate\Http\Client\ConnectionException
            || $e instanceof \Illuminate\Http\Client\RequestException) {
            return 'The AI service is temporarily unreachable. Please try again in a moment.';
        }

        // For our own RuntimeExceptions (safe, user-facing messages), pass the
        // message through but redact any secrets as a defense-in-depth backstop.
        $message = \App\Support\SecretRedactor::redact($e->getMessage());

        return $message !== '' ? $message : 'AI domain generation failed. Please try again.';
    }

    private function buildPrompt(): string
    {
        $prompt = trim($this->prompt);

        $modifiers = [
            'phonetic' => implode(' ', [
                'Creatively mutate the domain names using phonetic substitutions:',
                'replace letters with soundalike equivalents (e.g. "ph" for "f", "k" for hard "c", "i" for "y", "x" for "ex"),',
                'use homophones or near-homophones of key words from the idea,',
                'and drop silent letters or double consonants for a punchy look (e.g. "Flyte", "Kore", "Phyre", "Nyte").',
                'Names must still be pronounceable and evoke the original idea.',
            ]),
            'numbers' => implode(' ', [
                'Number Blend mode is selected: EVERY returned domain name MUST contain at least one digit from 0-9.',
                'Create one-word domain labels that blend digits into the name while staying pronounceable and brandable.',
                'Use meaningful sound substitutions such as "to/too" -> "2", "for/fore" -> "4", "ate/eight" -> "8", "one/won" -> "1", or "o/zero" -> "0".',
                'You may also append meaningful compact numbers such as 24, 360, or 101 when they fit the concept.',
                'Do not output any digit-free names. Bad: chronoscape, aetheria, nexusprime. Good: quest2play, realm8, raid360, lore4ge, nexu5, arca1a.',
                'Avoid random spammy strings of digits. Keep names short, clean, and startup-ready.',
            ]),
        ];

        if (isset($modifiers[$this->promptModifier])) {
            $prompt .= "\n\n" . $modifiers[$this->promptModifier];
        }

        $excluded = collect($this->excluded ?? [])
            ->map(fn ($name) => strtolower(trim((string) $name)))
            ->filter(fn ($name) => $name !== '')
            ->values()
            ->all();

        if (!empty($excluded)) {
            $prompt .= "\n\nand it's NOT " . implode(', ', $excluded);
        }

        return $prompt;
    }

    private function sanitizeDomains(array $domains): array
    {
        $stopWords = ['the','a','an','and','or','but','of','for','in','on','at','to','with','this','that','these','those','it','is','be','by','as','up','do'];

        $requiresDigit = $this->promptModifier === 'numbers';

        return array_values(array_filter(array_map(function ($name) {
            return strtolower(trim((string) $name));
        }, $domains), function ($name) use ($stopWords, $requiresDigit) {
            if (!preg_match('/^[a-z0-9][a-z0-9\-]{2,}[a-z0-9]$/i', $name)) {
                return false;
            }

            if ($requiresDigit && !preg_match('/\d/', $name)) {
                return false;
            }

            foreach (explode('-', $name) as $segment) {
                if (in_array($segment, $stopWords, true)) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function checkDomainAvailability(array $names, array $tlds): array
    {
        if (config('services.domain_lookup') === 'namecheap') {
            return app(NamecheapClient::class)->checkAvailability($names, $tlds);
        }

        $allDomains = [];
        foreach ($names as $name) {
            $base = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', explode('.', $name)[0]));
            foreach ($tlds as $tld) {
                $allDomains[] = $base . '.' . $tld;
            }
        }

        $scriptPath = base_path('python/domain-search/check_domain_availability.py');
        $uniqueNames = array_values(array_unique(array_map(fn ($d) => explode('.', $d)[0], $allDomains)));

        $args = ['python3', $scriptPath, '-t', ...$tlds, '--skip-http-check', '--', ...$uniqueNames];
        $result = Process::timeout(30)->run($args);

        if (!$result->successful()) {
            return ['results' => [], 'error' => 'WHOIS check failed: ' . $result->errorOutput()];
        }

        $results = [];
        foreach (explode("\n", trim($result->output())) as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $parsed = json_decode($line, true);
            if (!$parsed || !isset($parsed['domain'])) continue;

            $available = (bool) ($parsed['available'] ?? false);
            $forSale = (bool) ($parsed['for_sale'] ?? false);
            $tld = str_contains($parsed['domain'], '.') ? '.' . substr($parsed['domain'], strpos($parsed['domain'], '.') + 1) : null;

            $results[] = [
                'domain' => $parsed['domain'],
                'available' => $available && !$forSale,
                'taken' => !$available,
                'for_sale' => $forSale,
                'premium' => false,
                'premium_price' => null,
                'tld' => $tld,
                'error' => $parsed['error'] ?? null,
            ];
        }

        return ['results' => $results, 'error' => null];
    }
}
