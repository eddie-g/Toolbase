<?php

namespace App\Http\Controllers;

use App\Models\AiDomainRequest;
use App\Models\AiLogoRequest;
use App\Models\AiLogoPrice;
use App\Models\AiPriceLog;
use App\Models\CreditTransaction;
use App\Models\Document;
use App\Services\DeveloperChatClient;
use App\Services\NamecheapClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Traits\ResolvesExternalDns;

class DomainSearchController extends Controller
{
    use ResolvesExternalDns;
    private const DEFAULT_TLDS = ['com', 'ai', 'net', 'org'];
    private const GENERATE_CATEGORIES = ['space', 'tech', 'fantasy', 'scifi', 'romance', 'mystery', 'thriller', 'horror', 'adventure', 'historical', 'drama', 'action'];
    private const CATEGORY_RESULT_LIMIT = 10;

    public function index()
    {
        $tldOptions = $this->getTldOptions();
        $availableTlds = array_column($tldOptions, 'tld');

        $defaultTlds = $this->getDefaultSelectedTlds($availableTlds);

        return view('domain-search', [
            'tldOptions' => $tldOptions,
            'defaultTlds' => $defaultTlds,
        ]);
    }

    public function logoGenerator()
    {
        return view('logo-generator');
    }

    public function check(Request $request)
    {
        $request->validate([
            'names' => 'required|string|max:500',
            'tlds' => 'required|array|min:1',
            'tlds.*' => ['required', Rule::in($this->getAvailableTlds())],
        ]);

        $names = preg_split('/[\s,]+/', trim($request->input('names')));
        $names = array_filter(array_map('trim', $names));

        if (empty($names)) {
            return response()->json(['results' => [], 'error' => 'No domain names provided.'], 422);
        }

        $tlds = $request->input('tlds', $this->getDefaultSelectedTlds());

        $check = $this->checkDomainAvailability($names, $tlds);

        if ($check['error']) {
            return response()->json($check, 500);
        }

        return response()->json($check);
    }

    /**
     * Generate domain ideas from dictionary category scores and prefix/suffix.
     */
    public function generate(Request $request)
    {
        $request->validate([
            'prefix' => 'nullable|string|max:30|regex:/^[a-zA-Z0-9-]*$/',
            'suffix' => 'nullable|string|max:30|regex:/^[a-zA-Z0-9-]*$/',
            'category' => ['required', Rule::in(self::GENERATE_CATEGORIES)],
        ]);

        $prefix = $this->sanitizeDomainLabelPart((string) $request->input('prefix', ''));
        $suffix = $this->sanitizeDomainLabelPart((string) $request->input('suffix', ''));
        $category = strtolower((string) $request->input('category'));

        if ($prefix === '' && $suffix === '') {
            return response()->json([
                'names' => [],
                'error' => 'Enter a prefix or suffix to generate ideas.',
            ], 422);
        }

        $names = $this->generateCategoryDomains($prefix, $suffix, $category);

        return response()->json(['names' => $names, 'error' => null]);
    }

    /**
     * Generate names then check availability (combined endpoint).
     */
    public function generateAndCheck(Request $request)
    {
        $request->validate([
            'seed' => 'required|string|max:30|regex:/^[a-zA-Z]+$/',
            'tlds' => 'required|array|min:1',
            'tlds.*' => ['required', Rule::in($this->getAvailableTlds())],
            'count' => 'integer|min:10|max:200',
        ]);

        $seed = strtolower(trim($request->input('seed')));
        $count = $request->input('count', 100);
        $tlds = $request->input('tlds', $this->getDefaultSelectedTlds());

        // Step 1: Generate names
        $genScript = base_path('python/domain-search/generate_domain_names.py');
        $genResult = Process::timeout(10)->run([
            'python3', $genScript, $seed, '-n', (string) $count, '--json',
        ]);

        if (!$genResult->successful()) {
            return response()->json([
                'names' => [],
                'results' => [],
                'error' => 'Generation failed: ' . $genResult->errorOutput(),
            ], 500);
        }

        $names = json_decode($genResult->output(), true) ?? [];

        if (empty($names)) {
            return response()->json([
                'names' => [],
                'results' => [],
                'error' => 'No names could be generated.',
            ], 422);
        }

        // Step 2: Check availability (uses Namecheap or WHOIS depending on config)
        $check = $this->checkDomainAvailability($names, $tlds);

        return response()->json([
            'names' => $names,
            'results' => $check['results'],
            'error' => $check['error'],
        ], $check['error'] ? 500 : 200);
    }

    private function checkDomainAvailability(array $names, array $tlds): array
    {
        // Use Namecheap API when configured (batches all TLDs into one request)
        if (config('services.domain_lookup') === 'namecheap') {
            return app(NamecheapClient::class)->checkAvailability($names, $tlds);
        }

        // Fallback: WHOIS via Python script
        return $this->checkDomainAvailabilityWhois($names, $tlds);
    }

    /**
     * Legacy WHOIS-based domain check via the Python script.
     */
    private function checkDomainAvailabilityWhois(array $names, array $tlds): array
    {
        // Build full domain list
        $allDomains = [];
        foreach ($names as $name) {
            $baseName = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', explode('.', $name)[0]));
            foreach ($tlds as $tld) {
                $allDomains[] = $baseName . '.' . $tld;
            }
        }

        // Check cache first
        $results = [];
        $uncachedDomains = [];

        foreach ($allDomains as $domain) {
            $cacheKey = 'domain:' . $domain;
            $cached = Cache::get($cacheKey);

            if ($cached !== null) {
                $results[] = $cached;
            } else {
                $uncachedDomains[] = $domain;
            }
        }

        // Only check uncached domains
        if (!empty($uncachedDomains)) {
            $scriptPath = base_path('python/domain-search/check_domain_availability.py');

            // Extract base names and TLDs for the script
            $uncachedNames = array_unique(array_map(function ($d) {
                return explode('.', $d)[0];
            }, $uncachedDomains));

            // Skip HTTP check for speed (reduces check time from 7+ seconds to ~1 second per domain)
            $args = ['python3', $scriptPath, '-t', ...$tlds, '--skip-http-check', '--', ...$uncachedNames];

            $result = Process::timeout(30)->run($args);

            if (!$result->successful()) {
                return [
                    'results' => $results,
                    'error' => 'Some domains could not be checked: ' . $result->errorOutput(),
                ];
            }

            $output = $result->output();
            $newResults = $this->parseOutput($output);

            // Cache new results for 1 hour
            foreach ($newResults as $result) {
                $cacheKey = 'domain:' . $result['domain'];
                Cache::put($cacheKey, $result, now()->addHour());
                $results[] = $result;
            }
        }

        return ['results' => $results, 'error' => null];
    }

    /**
     * Start a background domain availability check job.
     * Returns a job_id that can be polled for results.
     */
    public function checkStart(Request $request)
    {
        $request->validate([
            'names' => 'required|array|min:1|max:100',
            'names.*' => 'string|max:100',
            'tlds' => 'required|array|min:1',
            'tlds.*' => ['required', Rule::in($this->getAvailableTlds())],
        ]);

        $names = array_values(array_filter(array_unique(
            array_map(function ($n) {
                return strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', explode('.', $n)[0]));
            }, $request->input('names'))
        )));
        $tlds = $request->input('tlds');

        // Namecheap fast-path: single batched HTTP call, return results immediately
        if (config('services.domain_lookup') === 'namecheap') {
            $check = app(NamecheapClient::class)->checkAvailability($names, $tlds);

            // Return in the same format as checkPoll so the frontend can handle both
            return response()->json([
                'results' => $check['results'],
                'done' => true,
                'offset' => count($check['results']),
                'error' => $check['error'],
                'instant' => true, // tells frontend no polling needed
            ]);
        }

        // Fallback: WHOIS background process (legacy)
        // Per-user concurrent job cap (max 3)
        $userId = $request->user()?->id ?? $request->ip();
        $userJobsKey = "domain-jobs-user:{$userId}";
        $activeJobIds = Cache::get($userJobsKey, []);

        // Prune completed jobs from the list
        $dir = storage_path('app/domain-checks');
        $activeJobIds = array_values(array_filter($activeJobIds, function ($jid) use ($dir) {
            $pidFile = "{$dir}/{$jid}.pid";
            if (!file_exists($pidFile)) return false;
            $pid = (int) trim(file_get_contents($pidFile));
            return $pid > 0 && file_exists("/proc/{$pid}");
        }));
        Cache::put($userJobsKey, $activeJobIds, now()->addMinutes(30));

        if (count($activeJobIds) >= 3) {
            return response()->json([
                'error' => 'Too many concurrent checks. Please wait for current checks to finish.',
            ], 429);
        }

        $jobId = Str::uuid()->toString();

        $dir = storage_path('app/domain-checks');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // Clean up old job files (older than 30 min)
        foreach (glob("{$dir}/*.jsonl") as $old) {
            if (filemtime($old) < time() - 1800) {
                @unlink($old);
                @unlink(str_replace('.jsonl', '.err', $old));
                @unlink(str_replace('.jsonl', '.pid', $old));
            }
        }

        $outputFile = "{$dir}/{$jobId}.jsonl";
        $errorFile  = "{$dir}/{$jobId}.err";
        $pidFile    = "{$dir}/{$jobId}.pid";

        $scriptPath = base_path('python/domain-search/check_domain_availability.py');
        $args = array_merge(
            ['python3', $scriptPath, '--jsonl', '-t'],
            $tlds,
            ['--'],
            $names
        );
        $command = implode(' ', array_map('escapeshellarg', $args));

        // Launch in background, capture PID
        $pid = (int) trim(shell_exec(sprintf(
            'nohup %s > %s 2> %s & echo $!',
            $command,
            escapeshellarg($outputFile),
            escapeshellarg($errorFile)
        )));
        file_put_contents($pidFile, $pid);

        Cache::put("domain-job:{$jobId}", [
            'started_at' => now()->toISOString(),
            'total' => count($names) * count($tlds),
            'user_id' => $userId,
        ], now()->addMinutes(30));

        // Track this job under the user's active list
        $activeJobIds[] = $jobId;
        Cache::put($userJobsKey, $activeJobIds, now()->addMinutes(30));

        return response()->json(['job_id' => $jobId]);
    }

    /**
     * Poll for results of a background domain availability check.
     * Returns new results since the given offset and a done flag.
     */
    public function checkPoll(Request $request)
    {
        $request->validate([
            'job_id' => 'required|string|max:50',
            'offset' => 'integer|min:0',
        ]);

        $jobId = $request->input('job_id');

        // Sanitize job_id to prevent path traversal
        if (!preg_match('/^[a-f0-9\-]{36}$/', $jobId)) {
            return response()->json(['error' => 'Invalid job ID.'], 400);
        }

        $job = Cache::get("domain-job:{$jobId}");
        if (!$job) {
            return response()->json(['error' => 'Job not found or expired.'], 404);
        }

        $offset = (int) $request->input('offset', 0);
        $dir = storage_path('app/domain-checks');
        $outputFile = "{$dir}/{$jobId}.jsonl";
        $pidFile    = "{$dir}/{$jobId}.pid";
        $errorFile  = "{$dir}/{$jobId}.err";

        $results = [];
        $newOffset = $offset;

        if (file_exists($outputFile)) {
            $lines = file($outputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $total = count($lines);

            for ($i = $offset; $i < $total; $i++) {
                $parsed = json_decode(trim($lines[$i]), true);
                if ($parsed && isset($parsed['domain'])) {
                    Cache::put('domain:' . $parsed['domain'], $parsed, now()->addHour());
                    $results[] = $parsed;
                    $newOffset = $i + 1;
                } else {
                    // Partial line write — stop here, retry on next poll
                    break;
                }
            }
        }

        // Check if background process is still running
        $done = true;
        if (file_exists($pidFile)) {
            $pid = (int) trim(file_get_contents($pidFile));
            if ($pid > 0 && file_exists("/proc/{$pid}")) {
                $done = false;
            }
        } else {
            // PID file not yet written — process still starting
            $done = false;
        }

        $error = null;
        if ($done && file_exists($errorFile)) {
            $errContent = trim(file_get_contents($errorFile));
            if ($errContent && $newOffset === 0) {
                $error = $errContent;
            }
        }

        return response()->json([
            'results' => $results,
            'done' => $done,
            'offset' => $newOffset,
            'error' => $error,
        ]);
    }

    private function parseOutput(string $output): array
    {
        $lines = explode("\n", $output);
        $results = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Match lines like: ✓   example.com                    AVAILABLE
            // or:               ✗   example.com                    taken
            // or:               $   example.com                    FOR SALE
            // or:               ?   example.com                    (error: ...)
            if (preg_match('/^([✓✗$?])\s+(\S+)\s+(.+)$/u', $line, $matches)) {
                $symbol = $matches[1];
                $domain = $matches[2];
                $status = trim($matches[3]);

                $result = [
                    'domain' => $domain,
                    'available' => $symbol === '✓',
                    'taken' => $symbol === '✗',
                    'for_sale' => $symbol === '$',
                    'error' => $symbol === '?' ? $status : null,
                    'tld' => '.' . pathinfo($domain, PATHINFO_EXTENSION),
                ];
                
                // Debug logging
                \Log::info('Parsed domain result', [
                    'symbol' => $symbol,
                    'domain' => $domain,
                    'for_sale' => $result['for_sale'],
                    'taken' => $result['taken']
                ]);
                
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Generate domain names using AI based on user prompt.
     */
    public function aiGenerate(Request $request, DeveloperChatClient $client)
    {
        // Check authentication - supports both session auth and Bearer token (Sanctum)
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'error' => 'You must be logged in to use AI Generator.',
                'domains' => [],
                'authenticated' => false,
            ], 401);
        }

        $request->validate([
            'prompt' => 'required|string|min:3|max:4000',
            'tlds' => 'nullable|array',
            'tlds.*' => ['required', Rule::in($this->getAvailableTlds())],
            'stream' => 'nullable|boolean',
        ]);

        $prompt = $request->input('prompt');
        $tlds = $request->input('tlds', $this->getDefaultSelectedTlds());
        if (!is_array($tlds) || count($tlds) === 0) {
            $tlds = $this->getDefaultSelectedTlds();
        }
        $stream = $request->boolean('stream');

        try {
            // Calculate how many names to generate so all fit in one Namecheap batch (max 50 domains)
            $tldCount = count($tlds);
            $maxNames = $tldCount > 0 ? (int) floor(50 / $tldCount) : 20;
            $maxNames = max(5, min($maxNames, 25)); // clamp between 5–25

            // Create system prompt for domain generation
            $systemPrompt = "You are a creative domain name generator. Generate {$maxNames} unique, brandable domain names based on the user's request. Return ONLY a JSON object with a 'domains' array containing domain name strings (without TLDs). Names should be:
- Short (4-15 characters)
- Memorable and brandable
- Easy to spell and pronounce
- Related to the user's prompt
- Creative but professional

Example response format:
{\"domains\": [\"techflow\", \"cloudnova\", \"pixelforge\", \"datazen\", \"codecraft\"]}";

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt],
            ];

            // Call Gemini API
            $data = $client->chat(
                $messages,
                0.9, // Higher temperature for creativity
                ['type' => 'json_object'],
                ['timeout' => 120]
            );

            $reply = $data['reply'];
            
            \Log::info('AI Domain Generation - Raw Reply', [
                'user_id' => $user->id,
                'prompt' => $prompt,
                'reply' => $reply,
                'reply_type' => gettype($reply),
            ]);
            
            // Parse JSON response
            $responseData = is_string($reply) ? json_decode($reply, true) : $reply;
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                \Log::error('JSON Parse Error', [
                    'error' => json_last_error_msg(),
                    'reply' => $reply,
                ]);
                throw new \Exception('Invalid JSON response from AI: ' . json_last_error_msg());
            }
            
            $domains = $responseData['domains'] ?? [];
            
            if (empty($domains)) {
                \Log::warning('No domains in response', [
                    'response_data' => $responseData,
                ]);
            }

            // Store request in database
            AiDomainRequest::create([
                'user_id' => $user->id,
                'prompt' => $prompt,
                'response' => $responseData,
                'model' => $data['response']['model'] ?? 'gemini-2.0-flash',
                'usage' => $data['response']['usageMetadata'] ?? null,
            ]);

            // Log to ai_price_log for tracking
            $usageMetadata = $data['response']['usageMetadata'] ?? [];
            $inputTokens = $usageMetadata['promptTokenCount'] ?? 0;
            $outputTokens = $usageMetadata['candidatesTokenCount'] ?? 0;
            $totalTokens = $usageMetadata['totalTokenCount'] ?? ($inputTokens + $outputTokens);
            
            // Calculate estimated cost (Gemini Flash pricing: $0.15 per 1M input, $0.60 per 1M output)
            $estimatedCost = ($inputTokens * 0.00000015) + ($outputTokens * 0.00000060);
            
            AiPriceLog::create([
                'session' => session()->getId(),
                'document_id' => null,
                'user_email' => $user->email,
                'request_type' => 'domain_generation',
                'model_name' => $data['response']['model'] ?? 'gemini-2.0-flash',
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $totalTokens,
                'image_count' => 0,
                'image_size' => null,
                'cost_usd' => null,
                'estimated_cost_usd' => $estimatedCost,
                'prompt_preview' => substr($prompt, 0, 255),
                'status' => 'completed',
            ]);

            if ($stream) {
                return response()->json([
                    'domains' => $domains,
                    'model' => $data['response']['model'] ?? 'gemini-2.0-flash',
                    'usage' => $data['response']['usageMetadata'] ?? null,
                    'error' => null,
                ]);
            }

            $check = $this->checkDomainAvailability($domains, $tlds);

            return response()->json([
                'domains' => $domains,
                'results' => $check['results'],
                'model' => $data['response']['model'] ?? 'gemini-2.0-flash',
                'usage' => $data['response']['usageMetadata'] ?? null,
                'error' => $check['error'],
            ], $check['error'] ? 500 : 200);

        } catch (\Illuminate\Http\Client\RequestException $e) {
            $statusCode = $e->response?->status();
            $errorBody = $e->response?->json();
            
            \Log::error('AI Domain Generation - API Error', [
                'user_id' => $user->id,
                'prompt' => $prompt,
                'status' => $statusCode,
                'error' => $errorBody,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'AI service error. Please try again.',
                'domains' => [],
                'debug' => config('app.debug') ? $errorBody : null,
            ], 500);
            
        } catch (\Exception $e) {
            \Log::error('AI Domain Generation Error', [
                'user_id' => $user->id,
                'prompt' => $prompt,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to generate domains. Please try again.',
                'domains' => [],
                'debug' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function getAvailableTlds(): array
    {
        return Cache::remember('domain-search:available-tlds', now()->addMinutes(30), function () {
            try {
                $table = $this->resolveTldTable();
                if (!$table) {
                    return self::DEFAULT_TLDS;
                }

                $tlds = DB::table($table)
                    ->select('tld')
                    ->whereNotNull('tld')
                    ->orderBy('tld')
                    ->pluck('tld')
                    ->map(fn ($tld) => strtolower(trim((string) $tld)))
                    ->filter(fn ($tld) => $tld !== '')
                    ->unique()
                    ->values()
                    ->all();

                return !empty($tlds) ? $tlds : self::DEFAULT_TLDS;
            } catch (\Throwable $e) {
                return self::DEFAULT_TLDS;
            }
        });
    }

    private function getTldOptions(): array
    {
        return Cache::remember('domain-search:tld-options', now()->addMinutes(30), function () {
            try {
                $table = $this->resolveTldTable();
                if (!$table) {
                    return array_map(fn ($tld) => [
                        'tld' => $tld,
                        'popularity' => null,
                        'manager' => null,
                    ], self::DEFAULT_TLDS);
                }

                return DB::table($table)
                    ->select('tld', 'popularity', 'manager')
                    ->whereNotNull('tld')
                    ->orderByRaw('popularity IS NULL, popularity ASC')
                    ->orderBy('tld')
                    ->get()
                    ->map(function ($row) {
                        return [
                            'tld' => strtolower(trim((string) $row->tld)),
                            'popularity' => $row->popularity,
                            'manager' => $row->manager,
                        ];
                    })
                    ->filter(fn ($row) => $row['tld'] !== '')
                    ->values()
                    ->all();
            } catch (\Throwable $e) {
                return array_map(fn ($tld) => [
                    'tld' => $tld,
                    'popularity' => null,
                    'manager' => null,
                ], self::DEFAULT_TLDS);
            }
        });
    }

    private function resolveTldTable(): ?string
    {
        foreach (['TLDs', 'tlds'] as $table) {
            try {
                DB::table($table)->limit(1)->get();
                return $table;
            } catch (\Throwable $e) {
                // Try next candidate.
            }
        }

        return null;
    }

    private function sanitizeDomainLabelPart(string $value): string
    {
        $clean = strtolower(trim($value));
        $clean = preg_replace('/[^a-z0-9-]/', '', $clean) ?? '';
        $clean = trim($clean, '-');

        return substr($clean, 0, 30);
    }

    private function generateCategoryDomains(string $prefix, string $suffix, string $category): array
    {
        $column = 'category_' . $category;

        $rows = DB::table('dictionary')
            ->select('word', $column, 'length')
            ->whereNotNull('word')
            ->where('length', '>=', 3)
            ->where('length', '<=', 12)
            ->where($column, '>', 0)
            ->orderByDesc($column)
            ->limit(250)
            ->get();

        $names = [];
        foreach ($rows as $row) {
            $core = $this->sanitizeDomainLabelPart((string) $row->word);
            if ($core === '') {
                continue;
            }

            $candidate = strtolower($prefix . $core . $suffix);
            $candidate = preg_replace('/-+/', '-', $candidate) ?? '';
            $candidate = trim($candidate, '-');

            if ($candidate === '' || strlen($candidate) < 3 || strlen($candidate) > 63) {
                continue;
            }

            if (!preg_match('/^[a-z0-9-]+$/', $candidate)) {
                continue;
            }

            $names[$candidate] = true;
            if (count($names) >= self::CATEGORY_RESULT_LIMIT) {
                break;
            }
        }

        return array_keys($names);
    }

    private function getDefaultSelectedTlds(?array $availableTlds = null): array
    {
        $availableTlds = $availableTlds ?? $this->getAvailableTlds();
        $defaultTlds = array_values(array_filter(
            self::DEFAULT_TLDS,
            fn ($tld) => in_array($tld, $availableTlds, true)
        ));

        return !empty($defaultTlds) ? $defaultTlds : self::DEFAULT_TLDS;
    }

    /**
     * Describe a logo image using Gemini Vision to generate a reusable prompt.
     */
    public function describeLogo(Request $request)
    {
        if (!$request->user()) {
            return response()->json(['error' => 'You must be logged in.'], 401);
        }

        $request->validate([
            'image_url' => 'required|url|max:2000',
        ]);

        $imageUrl = $request->input('image_url');

        try {
            $apiKey = config('services.gemini.api_key');
            $model = config('services.gemini.model', 'gemini-2.0-flash');
            $baseUrl = config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');

            // Fetch the image and send as base64 inline_data
            $imageData = Http::timeout(15)->get($imageUrl);
            if (!$imageData->successful()) {
                return response()->json(['error' => 'Could not fetch the image.'], 422);
            }

            $imageBytes = $imageData->body();
            $mimeType = $imageData->header('Content-Type') ?: 'image/png';
            $base64Image = base64_encode($imageBytes);

            $response = Http::timeout(30)->post(
                "{$baseUrl}/models/{$model}:generateContent?key={$apiKey}",
                [
                    'contents' => [
                        [
                            'parts' => [
                                [
                                    'text' => 'You are a logo design expert. Analyze this logo image and write a concise, visual description that could be used as a prompt to recreate or iterate on this design. Focus on: the visual style, colors, shapes, composition, typography style (if any), and overall mood. Do NOT mention brand names or readable text content — describe only the visual design elements. Keep it under 120 words. Write it as a direct design instruction, not a description (e.g. "A minimalist geometric..." not "This logo features..."). Output ONLY the prompt text, nothing else.',
                                ],
                                [
                                    'inline_data' => [
                                        'mime_type' => $mimeType,
                                        'data' => $base64Image,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.3,
                        'maxOutputTokens' => 300,
                    ],
                ]
            );

            if (!$response->successful()) {
                \Log::warning('Gemini vision API failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return response()->json(['error' => 'AI vision service failed.'], 502);
            }

            $data = $response->json();
            $description = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if (!$description) {
                return response()->json(['error' => 'Could not generate description.'], 500);
            }

            // Clean up the description
            $description = trim($description);
            $description = preg_replace('/^["\']+|["\']+$/', '', $description); // Strip wrapping quotes

            // Deduct Gemini vision cost (~$0.0001 per call)
            CreditTransaction::debit(
                userId: $request->user()->id,
                amount: 0.0001,
                service: 'logo_describe',
                modelName: $model,
                description: 'AI logo analysis (Gemini Vision)',
            );

            return response()->json([
                'prompt' => $description,
            ]);
        } catch (\Exception $e) {
            if ($e instanceof \Illuminate\Http\Client\ConnectionException) {
                \Log::error('Logo describe connection error - NO CHARGE', [
                    'error' => $e->getMessage(),
                    'user_id' => $request->user()->id,
                ]);
            } else {
                \Log::error('Logo describe error: ' . $e->getMessage());
            }
            
            $userMessage = $e instanceof \Illuminate\Http\Client\ConnectionException
                ? 'Unable to connect to the AI service. Please try again in a moment. Your account was not charged.'
                : 'Failed to analyze image.';
                
            return response()->json(['error' => $userMessage], 500);
        }
    }

    /**
     * Get real-time price estimate from fal.ai for logo generation.
     */
    public function estimateLogoPrice(Request $request)
    {
        $request->validate([
            'count' => 'nullable|integer|min:1|max:4',
            'pro' => 'nullable|boolean',
            'pro_size' => 'nullable|integer|in:512,1024,1536',
            'style' => 'nullable|string|in:professional,fantasy,future,retro,chrome,8bit,dotmatrix,lego',
            'bg_color' => 'nullable|string|max:20',
            'image_model' => 'nullable|string|in:flux,dalle,recraft',
            'output_format' => 'nullable|string|in:raster,vector',
            'image_format' => 'nullable|string|in:png,bmp',
            'recraft_substyle' => 'nullable|string|max:60',
        ]);

        $imageModel = $request->input('image_model', 'flux');
        $outputFormat = $request->input('output_format', 'raster');

        if ($imageModel === 'recraft') {
            $estimate = \App\Services\RecraftPricing::estimateLogoCost(
                imageCount: (int) $request->input('count', 4),
                size: '1024x1024',
                isPro: (bool) $request->input('pro', false),
                type: $outputFormat,
            );
        } elseif ($imageModel === 'dalle') {
            $estimate = AiLogoPrice::estimateDalleCost(
                imageCount: (int) $request->input('count', 4),
                resolution: '1024x1024',
                quality: (bool) $request->input('pro', false) ? 'hd' : 'standard',
            );
        } else {
            $estimate = AiLogoPrice::estimateCost(
                imageCount: (int) $request->input('count', 4),
                isPro: (bool) $request->input('pro', false),
                proSize: (int) $request->input('pro_size', 1024),
                style: $request->input('style', 'professional'),
                bgColor: $request->input('bg_color', 'white'),
                outputFormat: $outputFormat,
            );
        }

        // Include user's current balance in the estimate response
        $user = $request->user();
        $estimate['credit_balance'] = $user ? (float) $user->credit_balance : 0;

        return response()->json($estimate);
    }

    public function generateLogo(Request $request)
    {
        if (!$request->user()) {
            return response()->json([
                'error' => 'You must be logged in to generate logos.',
            ], 401);
        }

        $request->validate([
            'domain' => 'nullable|string|max:100',
            'style' => 'required|string|in:professional,fantasy,future,retro,chrome,8bit,dotmatrix,lego,minimalist',
            'count' => 'nullable|integer|min:1|max:4',
            'total_count' => 'nullable|integer|min:1|max:4',
            'batch_index' => 'nullable|integer|min:0|max:3',
            'custom_prompt' => 'required|string|min:2|max:500',
            'pro' => 'nullable|boolean',
            'pro_size' => 'nullable|integer|in:512,1024,1536',
            'icon_only' => 'nullable|boolean',
            'bg_color' => 'nullable|string|max:20',
            'image_model' => 'nullable|string|in:flux,dalle,recraft',
            'output_format' => 'nullable|string|in:raster,vector',
            'image_format' => 'nullable|string|in:png,bmp',
            'recraft_substyle' => 'nullable|string|max:60',
            'color_palette' => 'nullable|array|max:5',
            'color_palette.*' => 'string|max:20',
        ]);

        $iconOnly = (bool) $request->input('icon_only', false);
        $domain = $request->input('domain') ? trim($request->input('domain')) : null;

        // Domain is required unless icon-only mode
        if (!$iconOnly && !$domain) {
            return response()->json([
                'error' => 'Domain name is required when Text in Logo is enabled.',
            ], 422);
        }

        $style = $request->input('style');
        $imageCount = $request->input('count', 1);
        $totalCount = $request->input('total_count', $imageCount);
        $batchIndex = $request->input('batch_index', 0);
        $customPrompt = $request->input('custom_prompt');
        $isPro = (bool) $request->input('pro', false);
        $proSize = (int) $request->input('pro_size', 1024);
        $bgColor = $request->input('bg_color', 'white');
        $imageModel = $request->input('image_model', 'flux');
        $outputFormat = $request->input('output_format', 'raster');
        $imageFormat = $request->input('image_format', 'png');
        $colorPalette = $request->input('color_palette');
        $recraftSubstyle = $request->input('recraft_substyle');

        // DALL-E always produces raster
        if ($imageModel === 'dalle') {
            $outputFormat = 'raster';
        }

        // ── Balance check: reject if user can't afford the estimated cost ──
        $user = $request->user();
        $userBalance = (float) $user->credit_balance;

        // Quick pre-check with a generous minimum threshold
        if ($userBalance <= 0) {
            return response()->json([
                'error' => 'Insufficient balance. Please add credits before generating logos.',
                'credit_balance' => $userBalance,
            ], 402);
        }

        // Calculate cost estimate using total count for proper pricing
        if ($imageModel === 'recraft') {
            $costEstimate = \App\Services\RecraftPricing::estimateLogoCost(
                imageCount: $totalCount,
                size: '1024x1024',
                isPro: $isPro,
                type: $outputFormat,
            );
        } elseif ($imageModel === 'dalle') {
            $costEstimate = AiLogoPrice::estimateDalleCost(
                imageCount: $totalCount,
                resolution: '1024x1024',
                quality: $isPro ? 'hd' : 'standard',
            );
        } else {
            $costEstimate = AiLogoPrice::estimateCost(
                imageCount: $totalCount,
                isPro: $isPro,
                proSize: $proSize,
                style: $style,
                bgColor: $bgColor,
                outputFormat: $outputFormat,
            );
        }
        
        // Calculate per-image cost for this single request
        $costPerImage = $costEstimate['cost_per_image'];
        $estimatedCostForThisImage = $costPerImage;

        // ── Precise balance check against estimated cost (for this single image) ──
        if ($estimatedCostForThisImage > 0 && $userBalance < $estimatedCostForThisImage) {
            return response()->json([
                'error' => 'Insufficient balance. This generation costs ~$' . number_format($estimatedCostForThisImage, 4) . ' but your balance is $' . number_format($userBalance, 4) . '. Please add credits.',
                'credit_balance' => $userBalance,
                'estimated_cost' => $estimatedCostForThisImage,
            ], 402);
        }

        // Extract the core brand concept from the domain name (strip TLD if present)
        $brandName = preg_replace('/\.(com|net|org|io|co|ai|app|dev|xyz|tech|me)$/i', '', $domain);
        $brandUpper = strtoupper($brandName);
        // Try to infer what the brand is about from its name
        $brandConcept = str_replace(['-', '_', '.'], ' ', $brandName);

        // If user provided a custom description, extract visual concept only
        // Strip any ALL-CAPS phrases and size/style directives to prevent them rendering as text
        $customElement = '';
        if ($customPrompt) {
            // Remove ALL-CAPS words (3+ chars) that Flux might render as text
            $cleaned = preg_replace('/\b[A-Z]{3,}\b/', '', $customPrompt);
            // Remove common directive phrases
            $cleaned = preg_replace('/\b(make|put|add|write|show|display|include|type|spell)\s+(it|the|a|an)?\s*/i', '', $cleaned);
            // Remove size directives
            $cleaned = preg_replace('/\b(large|big|huge|small|tiny|giant|massive|enormous)\b/i', '', $cleaned);
            // Collapse whitespace
            $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));
            if ($cleaned) {
                $customElement = " A visual icon element of {$cleaned} is integrated into the logo design. Do not render any words or letters from this description.";
            }
        }

        // Typography-first prompts with style hooks and avoidance language
        // Build color palette instruction
        if (!empty($colorPalette) && is_array($colorPalette)) {
            $colorNames = implode(', ', $colorPalette);
            $colorInstruction = "Use exactly this color palette for the logo artwork and typography: {$colorNames}. These colors are only for the logo elements, NOT the background.";
        } else {
            $colorInstruction = null; // Let style defaults apply
        }

        // Determine background color instruction
        $bgInstruction = match($bgColor) {
            'black' => 'isolated on a solid black background',
            'transparent' => 'isolated on a plain transparent background with no background elements',
            default => str_starts_with($bgColor, '#')
                ? "isolated on a solid {$bgColor} colored background"
                : 'isolated on a solid white background',
        };

        if ($iconOnly) {
            // Icon-only mode: no text at all, pure symbol/icon
            // Use custom element if provided, otherwise use a generic concept hint without the brand name
            $conceptHint = $customElement ? $customElement : ' A unique abstract symbol.';
            $noExtraText = " There is absolutely NO text, NO letters, NO words, NO numbers, NO typography anywhere in the image. Zero text of any kind. Pure graphic symbol only.";

            $profColor = $colorInstruction ?? 'navy blue and gold color palette.';
            $fantColor = $colorInstruction ?? 'rich emerald green and antique gold color palette.';
            $futColor = $colorInstruction ?? 'glowing neon cyan and electric purple color palette,';
            $retroColor = $colorInstruction ?? 'red, green, blue and yellow color palette.';

            $stylePrompts = [
                'professional' => "A premium corporate icon mark.{$conceptHint} A single bold geometric symbol. Monolithic, thick lines, emblem style, {$profColor} Secure, established, Fortune 500 quality. Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. No shadows, no 3D effects, no gradients, no cluttered details, avoid photorealism, avoid messy lines. High contrast, professional design, 4k.{$noExtraText}",
                'fantasy' => "An epic fantasy-themed icon mark.{$conceptHint} A single ornate magical symbol. Elven runes, enchanted forest motifs, mythical creatures, ancient scrollwork, Lord of the Rings inspired aesthetics, {$fantColor} Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. No shadows, no 3D effects, no cluttered details, avoid photorealism, avoid messy lines. Epic fantasy design, 4k.{$noExtraText}",
                'future' => "A futuristic sci-fi icon mark.{$conceptHint} A single sleek angular geometric symbol. Holographic elements, circuit board patterns, space-age aesthetics, {$futColor} starfield accents, advanced technology motifs. Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. No cluttered details, avoid photorealism, avoid messy lines. Futuristic sci-fi design, 4k.{$noExtraText}",
                'retro' => "A vibrant retro vector design icon mark.{$conceptHint} Surrounded by a colorful retro sunburst, {$retroColor} Captures the essence of a fun vacation feel, minimalist retro style. Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. No cluttered details, avoid photorealism. Retro design, 4k.{$noExtraText}",
                'chrome' => "A premium corporate icon mark.{$conceptHint} A single bold geometric symbol. Monolithic, thick lines, emblem style, {$profColor} Secure, established, Fortune 500 quality. Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. No shadows, no 3D effects, no gradients, no cluttered details, avoid photorealism, avoid messy lines. High contrast, professional design, 4k.{$noExtraText}",
                '8bit' => "An epic fantasy-themed icon mark.{$conceptHint} A single ornate magical symbol. Engraved polished gold with beveled edges, intricate filigree and ornamental carvings, glowing blue arcane crystals, ancient metal structures, magical geometric forms, {$fantColor} Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. Fantasy RPG design, 4k.{$noExtraText}",
                'dotmatrix' => "A stippled dot art icon mark.{$conceptHint} A single bold symbol rendered entirely in stippling technique. {$profColor} Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. Professional dot art design, 4k.{$noExtraText}",
                'lego' => "A glossy sticker-style icon mark.{$conceptHint} Thick clean outlines, soft shadows, toy plastic material. {$profColor} Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. Sticker design, 4k.{$noExtraText}",
                'minimalist' => "A minimalist icon mark.{$conceptHint} A single clean, modern symbol using flat design principles. Subtle geometric shapes, {$profColor} Plain or white background. Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. No gradients, no shadows, no clutter. Visually balanced professional minimalist design, 4k.{$noExtraText}",
            ];
        } else {
            // With brand name text
            $noExtraText = " The ONLY text in the entire image is \"{$brandUpper}\". Do not add any other words, letters, taglines, slogans, or captions anywhere in the image.";

            $profColor = $colorInstruction ?? 'navy blue and gold color palette.';
            $fantColor = $colorInstruction ?? 'rich emerald green and antique gold color palette.';
            $futColor = $colorInstruction ?? 'glowing neon cyan and electric purple color palette,';
            $retroColor = $colorInstruction ?? 'red, green, blue and yellow color palette.';

            $stylePrompts = [
                'professional' => "A premium corporate logo. The centerpiece is the word \"{$brandUpper}\" in an elegant, refined custom serif typeface with perfectly spaced letters.{$customElement} Monolithic, thick lines, emblem style, {$profColor} Secure, established, Fortune 500 quality. Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. No shadows, no 3D effects, no gradients, no cluttered details, avoid photorealism, avoid messy lines. High contrast, professional design, 4k.{$noExtraText}",
                'fantasy' => "An epic fantasy-themed logo. The centerpiece is the word \"{$brandUpper}\" in an ornate, medieval-inspired custom typeface with elegant serifs and decorative flourishes.{$customElement} Elven runes, enchanted forest motifs, mythical creatures, ancient scrollwork, Lord of the Rings inspired aesthetics, {$fantColor} Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. No shadows, no 3D effects, no cluttered details, avoid photorealism, avoid messy lines. Epic fantasy design, 4k.{$noExtraText}",
                'future' => "A futuristic sci-fi logo. The centerpiece is the word \"{$brandUpper}\" in a sleek, angular, cyberpunk-inspired custom typeface with sharp edges and neon accents.{$customElement} Holographic elements, circuit board patterns, space-age aesthetics, {$futColor} starfield accents, advanced technology motifs. Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. No cluttered details, avoid photorealism, avoid messy lines. Futuristic sci-fi design, 4k.{$noExtraText}",
                'retro' => "A vibrant retro vector design logo. The centerpiece is the word \"{$brandUpper}\" in a bold retro typeface.{$customElement} Surrounded by a colorful retro sunburst, {$retroColor} Captures the essence of a fun vacation feel, minimalist retro style. Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. No cluttered details, avoid photorealism. Retro design, 4k.{$noExtraText}",
                'chrome' => "A premium corporate logo. The centerpiece is the word \"{$brandUpper}\" in an elegant, refined custom serif typeface with perfectly spaced letters.{$customElement} Monolithic, thick lines, emblem style, {$profColor} Secure, established, Fortune 500 quality. Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. No shadows, no 3D effects, no gradients, no cluttered details, avoid photorealism, avoid messy lines. High contrast, professional design, 4k.{$noExtraText}",
                '8bit' => "An epic fantasy-themed logo. The centerpiece is the word \"{$brandUpper}\" in ornate medieval high-fantasy typography with engraved polished gold, beveled edges and filigree.{$customElement} Glowing blue arcane crystals, ancient metal structures, magical geometric forms, {$fantColor} Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. Fantasy RPG design, 4k.{$noExtraText}",
                'dotmatrix' => "A stippled dot art logo with \"{$brandUpper}\" text.{$customElement} {$profColor} Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. Professional dot art design, 4k.{$noExtraText}",
                'lego' => "A glossy sticker-style logo with \"{$brandUpper}\" in a decorative banner.{$customElement} Thick clean outlines, soft shadows, toy plastic material. {$profColor} Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. Sticker design, 4k.{$noExtraText}",
                'minimalist' => "A minimalist logo design with \"{$brandUpper}\" in a stylish sans-serif font.{$customElement} Clean, modern, and simple, using flat design principles. Subtle geometric shapes or symbols, {$profColor} Plain or white background. Clean flat artwork, centered 1:1 square composition, {$bgInstruction}. No gradients, no shadows, no clutter. Visually balanced professional minimalist design, 4k.{$noExtraText}",
            ];
        }

        $prompt = $stylePrompts[$style];

        // ── Build a DALL-E-specific prompt (structured, anti-collage format) ──
        if ($imageModel === 'dalle') {
            $dalleDesc = trim($customPrompt ?? '');

            // Color line
            $dalleColorLine = !empty($colorPalette) && is_array($colorPalette)
                ? implode(' and ', $colorPalette)
                : match($style) {
                    'fantasy' => 'emerald green and antique gold',
                    'future'  => 'neon cyan and electric purple',
                    'retro'   => 'red, green, blue and yellow',
                    default   => 'navy blue and gold',
                };

            // Build a descriptive color list for the prompt
            // DALL-E responds better to named colors + hex than raw hex alone
            if (!empty($colorPalette) && is_array($colorPalette)) {
                $namedColors = [];
                foreach ($colorPalette as $hex) {
                    $namedColors[] = $hex . ' (' . self::hexToColorName($hex) . ')';
                }
                $dalleColorList = implode(', ', $namedColors);
            } else {
                $dalleColorList = $dalleColorLine;
            }

            // Background for chrome template
            $chromeBg = match($bgColor) {
                'black' => 'dark black background',
                'white' => 'pure white background',
                default => str_starts_with($bgColor, '#')
                    ? "{$bgColor} background"
                    : 'soft light gray background',
            };

            // Chrome style uses 3D metallic render template
            if ($style === 'chrome') {
                // Determine the symbol description
                $chromeSymbol = $dalleDesc ?: 'logo symbol';

                if ($iconOnly) {
                    // Icon-only: single 3D object
                    $prompt = "A high-resolution 3D render of the {$chromeSymbol} made of polished sterling silver with a shiny metallic texture, floating on a {$chromeBg} in a minimalistic studio setup. The logo is captured from a frontal close-up, illuminated by soft diffused studio light with soft shadows, showcasing micro-etched patterns and a sleek and modern ambiance, with a faint geometric lines subtly integrated, rendered in 4K HDR for hyper-detailed clarity.";
                } else {
                    // Text ON: symbol + brand name as 3D chrome logo
                    $prompt = "A high-resolution 3D render of a custom chrome logo featuring a realistic {$chromeSymbol} and the exact word \"{$brandUpper}\".\n\n"
                        . "The text must read exactly: {$brandUpper}\n"
                        . "No spelling changes.\n"
                        . "No missing letters.\n"
                        . "No extra letters.\n\n"
                        . "The word {$brandUpper} is a physical 3D extruded object made of polished sterling silver chrome with real thickness and depth.\n\n"
                        . "Use clean, modern, geometric sans-serif typography.\n\n"
                        . "Avoid ornamental script, gothic, or decorative fonts.\n\n"
                        . "The letters must be clear, legible, evenly spaced, and perfectly readable.\n\n"
                        . "The vehicle and the text share the same polished chrome metallic material.\n\n"
                        . "{$chromeBg}.\n"
                        . "Minimal presentation.\n"
                        . "Soft uniform lighting.\n\n"
                        . "Ultra-clean professional 3D logo render.\n\n"
                        . "4K resolution.";
                }
            } elseif ($style === 'retro') {
                // Retro vibrant sunburst style
                $retroSubject = $dalleDesc ?: ($iconOnly ? 'logo symbol' : "{$brandUpper} logo");
                $prompt = "A vibrant retro vector design featuring {$retroSubject} on vacation. Surrounded by a colorful retro sunburst with red, green, blue and yellow, the design captures the essence of a fun vacation, on a plain full black background, suitable for t-shirt printing, minimalist retro style";
                if (!$iconOnly) {
                    $prompt .= ". The only text in the design is \"{$brandUpper}\".";
                }
            } elseif ($style === '8bit') {
                // 8-bit pixel-art arcade style
                $eightBitText = $iconOnly ? 'a retro arcade icon' : "the brand name \"{$brandUpper}\"";
                $prompt = "Design a retro 8-bit pixel-art logo for {$eightBitText}.\n"
                    . "Style: classic 1980s arcade game title screen, chunky pixel typography, crisp square pixels, limited 16-color palette, high contrast, clean silhouette, readable at small sizes.\n"
                    . "Include: icon + wordmark, centered composition, transparent or solid single-color background, subtle pixel glow/shadow, no gradients, no anti-aliasing, no blur.\n"
                    . "Output: vector-like clean edges, branding-ready, multiple variations of colorways and layout, exact text must read \"{$brandUpper}\" with correct spelling.";
            } elseif ($style === 'dotmatrix') {
                // Dot matrix stippling art style
                $dotSubject = $dalleDesc ?: 'an iconic symbol';
                $prompt = "A highly detailed stippled dot art illustration of {$dotSubject}.\n"
                    . "Style: Pure stippling technique using only black dots of varying sizes and densities to create shading and depth.\n"
                    . "Technique: Pointillism, dot work, no lines, no hatching, only circular dots, high-contrast monochromatic artwork.\n"
                    . "The entire image is composed of thousands of carefully placed dots - smaller dots for lighter areas, larger/denser dots for darker areas.\n"
                    . "Composition: Centered portrait-style composition, detailed facial features and textures rendered entirely in dots, isolated on pure white background.\n"
                    . "Quality: Professional engraving-style stipple art, museum-quality pointillism, sharp detailed dot work, 4K resolution.\n"
                    . "No text, no words, no letters anywhere in the image.";
            } elseif ($style === 'lego') {
                // Lego sticker style
                $legoSubject = $dalleDesc ?: 'cute characters';
                $legoText = $iconOnly ? '' : "\nText: The text \"{$brandUpper}\" is displayed in a decorative banner or ribbon, using clean rounded typography with thick outlines.";
                $legoColors = !empty($colorPalette) && is_array($colorPalette) 
                    ? "\nColors: Use this exact color palette for the characters and design elements: {$dalleColorList}."
                    : "\nColors: Use vibrant, friendly colors suitable for toy-like sticker characters.";
                $prompt = "Style: Sticker style with thick clean outlines, soft shadows underneath, and glossy toy plastic material.\n"
                    . "Inspired by cute LEGO fan art.\n"
                    . "Minimal detail, smooth surfaces, friendly and wholesome aesthetic.\n"
                    . "Subject: {$legoSubject} rendered in a simplified, adorable LEGO style with bold black outlines and vibrant glossy colors.\n"
                    . $legoColors . "\n"
                    . $legoText . "\n"
                    . "Composition: Centered composition, 1:1 square ratio, isolated on a pure white background.\n"
                    . "Quality: Professional LEGO illustration, crisp clean artwork, high contrast, 4K resolution.";
            } elseif ($style === 'minimalist') {
                $minimalistSubject = trim($dalleDesc) ?: 'an abstract symbol';
                $minimalistColors = !empty($colorPalette) && is_array($colorPalette) 
                    ? "Color palette: {$dalleColorList}."
                    : "Color palette: navy blue and gray.";
                
                if ($iconOnly) {
                    $prompt = "Design one ultra-minimalist logo icon for {$minimalistSubject}. "
                        . "Use exactly one simple symbol built from 1-2 geometric primitives only. "
                        . "Flat vector look, monoline or solid fill, generous negative space, no decoration. "
                        . $minimalistColors . " "
                        . "Background: plain white. Composition: centered 1:1. "
                        . "Hard constraints: NO text, NO letters, NO numbers, NO tagline, NO border badge, NO scene, NO collage, NO multiple options. "
                        . "Output exactly one clean icon mark.";
                } else {
                    $prompt = "Design one ultra-minimalist logo for brand \"{$brandUpper}\". "
                        . "Concept cue for the icon: {$minimalistSubject}. "
                        . "Layout rule: one simple geometric icon on the left + one horizontal wordmark on the right. "
                        . "Typography rule: use a clean sans-serif, uppercase, clear spacing, high legibility. "
                        . "Text must read EXACTLY \"{$brandUpper}\" with correct spelling and all letters present. "
                        . "Do not change, split, stylize into symbols, or omit any characters in \"{$brandUpper}\". "
                        . "The ONLY text allowed is \"{$brandUpper}\". No tagline, no extra words, no hidden letters. "
                        . $minimalistColors . " "
                        . "Background: plain white. Flat design only: no gradients, no shadows, no textures, no 3D. "
                        . "Hard constraints: one logo only, no collage, no grid, no mockup, no busy elements. "
                        . "Prioritize simplicity, whitespace, and text readability over decoration.";
                }
            } else {
                $prompt = "A high-resolution 3D render of a logo made of polished sterling silver with a shiny metallic texture, floating on a {$chromeBg} in a minimalistic studio setup, rendered in 4K HDR for hyper-detailed clarity.";
            }
        }

        // ── Build a Recraft-specific structured logo prompt (max 1000 chars) ──
        if ($imageModel === 'recraft') {
            $lines = [];
            $subjectDesc = trim($customPrompt ?? '');

            // ── Style + Theme (no need to say "vector" — the endpoint handles that) ──
            $theme = match($style) {
                'fantasy' => 'dark fantasy, mythical',
                'future'  => 'futuristic sci-fi, cyberpunk',
                'retro'   => 'vibrant retro, colorful sunburst, vacation feel',
                default   => 'premium corporate, clean',
            };
            $lines[] = "Style: Logo, flat minimalist emblem. {$theme}.";

            // ── Subject ──
            if ($iconOnly) {
                $lines[] = $subjectDesc
                    ? "Subject: Bold geometric silhouette of {$subjectDesc}. All elements merged into one unified icon."
                    : 'Subject: Abstract geometric silhouette symbol, one unified icon shape.';
            } else {
                $lines[] = $subjectDesc
                    ? "Subject: Logo with \"{$brandUpper}\" and a geometric silhouette of {$subjectDesc}, merged into one emblem."
                    : "Subject: Logo with \"{$brandUpper}\" and a geometric silhouette emblem mark.";
            }

            // ── Silhouette ──
            $lines[] = 'Silhouette: Single filled shape like a rubber stamp. No internal line-art or contour lines. Features implied only by outer contour. Negative space only as clean cut-outs.';

            // ── Text ──
            $lines[] = $iconOnly
                ? 'Text: Zero text, letters, or characters of any kind.'
                : "Text: Only \"{$brandUpper}\" in clean sans-serif. No taglines or decorative text.";

            // ── Design ──
            $lines[] = 'Design: Thick monolithic lines, bold chunky shapes, smooth geometric curves. Recognisable at any size.';

            // ── Color ──
            if (!empty($colorPalette) && is_array($colorPalette)) {
                $paletteHexes = implode(', ', $colorPalette);
                $colorDesc = $paletteHexes;
            } else {
                $colorDesc = match($style) {
                    'fantasy' => 'emerald green and antique gold',
                    'future'  => 'neon cyan and electric purple',
                    'retro'   => 'red, green, blue and yellow',
                    default   => 'navy blue and gold',
                };
            }
            $lines[] = "Color: Silhouette uses {$colorDesc}. Colors apply only to the logo shape, not the background.";

            // ── Background ──
            $bgDesc = match($bgColor) {
                'black' => '#000000 black',
                'transparent' => 'transparent',
                default => str_starts_with($bgColor, '#')
                    ? $bgColor
                    : '#FFFFFF white',
            };
            $lines[] = "Background: Solid {$bgDesc}, uniform, no texture or gradient.";

            // ── Composition + Rendering ──
            $lines[] = 'Composition: Centered 1:1 square, equal breathing room, bilateral symmetry.';
            $lines[] = 'Rendering: No gradients, shadows, glows, bevels or textures. Flat color fills, clean edges. Production-ready logo mark.';

            $prompt = implode("\n", $lines);
        }

        // Determine model name for logging
        if ($imageModel === 'recraft') {
            $formatTag = $outputFormat === 'vector' ? 'vector' : 'raster';
            $modelName = $isPro ? "recraft-v3-{$formatTag}" : "recraft-v2-{$formatTag}";
            $imageSize = '1024x1024';
            $requestType = $isPro
                ? ($outputFormat === 'vector' ? 'logo_recraft_v3' : 'logo_recraft_v3_raster')
                : ($outputFormat === 'vector' ? 'logo_recraft_vector' : 'logo_recraft_raster');
        } elseif ($imageModel === 'dalle') {
            $modelName = 'dall-e-3';
            $imageSize = '1024x1024';
            $requestType = $isPro ? 'logo_dalle_hd' : 'logo_dalle';
        } else {
            $modelName = $isPro ? 'fal-ai/flux-pro/v1.1' : 'fal-ai/flux/schnell';
            $imageSize = $isPro ? $proSize . 'x' . $proSize : '512x512';
            $requestType = $isPro ? 'logo_pro' : 'logo_generation';
        }

        $logoRequest = AiLogoRequest::create([
            'user_id' => $request->user()->id,
            'domain' => $domain,
            'style' => $style . ($isPro ? '_pro' : ''),
            'model' => $modelName,
            'seed_number' => null,
            'prompt' => $prompt,
            'original_prompt' => $customPrompt ? trim((string) $customPrompt) : null,
            'status' => 'pending',
        ]);

        // Create price log entry (pending) - log actual count for this request, note batch in preview
        $priceLog = AiLogoPrice::create([
            'user_id' => $request->user()->id,
            'ai_logo_request_id' => $logoRequest->id,
            'session' => session()->getId(),
            'user_email' => $request->user()->email,
            'request_type' => $requestType,
            'model_name' => $modelName,
            'image_count' => $imageCount,
            'image_size' => $imageSize,
            'num_inference_steps' => $imageModel === 'recraft' ? 0 : ($imageModel === 'dalle' ? 0 : ($isPro ? 28 : 8)),
            'guidance_scale' => ($imageModel === 'recraft' || $imageModel === 'dalle') ? 0 : 3.50,
            'cost_per_image' => $costPerImage,
            'estimated_cost_usd' => $estimatedCostForThisImage,
            'status' => 'pending',
            'prompt_preview' => substr($prompt, 0, 240) . ($totalCount > 1 ? " [img " . ($batchIndex + 1) . "/{$totalCount}]" : ''),
        ]);

        // ── Dispatch the generation job to the queue ──
        \App\Jobs\GenerateLogoJob::dispatch(
            userId: $user->id,
            logoRequestId: $logoRequest->id,
            priceLogId: $priceLog->id,
            params: [
                'image_model' => $imageModel,
                'output_format' => $outputFormat,
                'is_pro' => $isPro,
                'pro_size' => $proSize,
                'image_count' => $imageCount,
                'prompt' => $prompt,
                'bg_color' => $bgColor,
                'domain' => $domain,
                'style' => $style,
                'icon_only' => $iconOnly,
                'color_palette' => $colorPalette,
                'recraft_substyle' => $recraftSubstyle,
                'total_count' => $totalCount,
                'cost_per_image' => $costPerImage,
                'model_name' => $modelName,
            ],
        );

        return response()->json([
            'logo_request_id' => (int) $logoRequest->id,
            'status' => 'queued',
            'message' => 'Logo generation has been queued. Poll /domain-search/logo-status/' . $logoRequest->id . ' for results.',
            'credit_balance' => (float) $user->credit_balance,
            'estimated_cost' => $estimatedCostForThisImage,
        ]);
    }

    /**
     * Poll the status of a queued logo generation job.
     *
     * Returns one of:
     *  - { status: "pending"|"processing" }   → keep polling
     *  - { status: "completed", ... }          → job done, here are your images
     *  - { status: "failed"|"error", error }   → job failed, stop polling
     */
    public function logoStatus(Request $request, AiLogoRequest $logoRequest)
    {
        // Make sure the user owns this request
        if (!$request->user() || $logoRequest->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $status = $logoRequest->status;

        if (in_array($status, ['pending', 'processing'])) {
            return response()->json(['status' => $status]);
        }

        if ($status === 'completed') {
            $resultData = $logoRequest->result_data
                ? json_decode($logoRequest->result_data, true)
                : null;

            // Refresh user balance
            $request->user()->refresh();

            return response()->json(array_merge([
                'status' => 'completed',
                'logo_request_id' => (int) $logoRequest->id,
                'credit_balance' => (float) $request->user()->credit_balance,
            ], $resultData ?? []));
        }

        // failed or error
        return response()->json([
            'status' => $status,
            'error' => $logoRequest->error_message ?? 'Logo generation failed.',
            'credit_balance' => (float) $request->user()->credit_balance,
        ]);
    }

    /**
     * Convert raw API error messages into user-friendly text.
     */
    private function friendlyErrorMessage(string $raw): string
    {
        if (str_contains($raw, 'content filters') || str_contains($raw, 'content_policy') || str_contains($raw, 'safety system')) {
            return 'Your prompt was flagged by the AI safety filter. Please rephrase your description and try again — avoid violent, sexual, or trademarked content.';
        }
        if (str_contains($raw, 'rate limit') || str_contains($raw, 'Rate limit')) {
            return 'The AI service is temporarily busy. Please wait a moment and try again.';
        }
        if (str_contains($raw, 'Billing hard limit') || str_contains($raw, 'billing')) {
            return 'DALL-E 3 is temporarily unavailable. Please use another model.';
        }
        if (str_contains($raw, 'quota')) {
            return 'The AI service quota has been reached. Please try again later or switch to a different model.';
        }
        if (str_contains($raw, 'invalid_api_key') || str_contains($raw, 'Incorrect API key')) {
            return 'There is a configuration issue with the AI service. Please contact support.';
        }
        return $raw;
    }

    /**
     * Return similar saved icon-only logo ideas for the current prompt.
     * Similarity is lexical (token + phrase overlap), tuned for short prompts.
     */
    public function logoSimilarIdeas(Request $request)
    {
        if (!$request->user()) {
            return response()->json([
                'error' => 'You must be logged in to view similar ideas.',
            ], 401);
        }

        $request->validate([
            'prompt' => 'required|string|min:3|max:500',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $prompt = trim((string) $request->input('prompt'));
        $limit = (int) $request->input('limit', 8);

        $rows = AiLogoRequest::query()
            ->where('status', 'completed')
            ->where('storage_type', 'path') // saved local icon-only outputs
            ->whereNotNull('original_prompt')
            ->whereNotNull('image_urls')
            ->orderByDesc('id')
            ->limit(600)
            ->get(['id', 'domain', 'style', 'original_prompt', 'image_urls', 'created_at']);

        $scored = [];
        foreach ($rows as $row) {
            $candidatePrompt = trim((string) $row->original_prompt);
            if ($candidatePrompt === '') {
                continue;
            }

            $score = $this->computePromptSimilarity($prompt, $candidatePrompt);
            if ($score < 0.42) {
                continue;
            }

            $urls = array_values(array_filter((array) $row->image_urls, function ($url) {
                return is_string($url) && $url !== '';
            }));
            if (empty($urls)) {
                continue;
            }

            // Normalize absolute URLs to relative paths so they work regardless of host
            $urls = array_map(function ($url) {
                $parsed = parse_url($url);
                if (isset($parsed['path']) && isset($parsed['host'])) {
                    return $parsed['path'];
                }
                return $url;
            }, $urls);

            $scored[] = [
                'id' => $row->id,
                'domain' => $row->domain,
                'style' => $row->style,
                'prompt' => $candidatePrompt,
                'score' => round($score, 4),
                'image_urls' => array_slice($urls, 0, 4),
                'created_at' => optional($row->created_at)->toIso8601String(),
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $ideas = array_slice($scored, 0, $limit);

        return response()->json([
            'ideas' => $ideas,
            'count' => count($ideas),
        ]);
    }

    public function editLogo(Request $request, AiLogoRequest $logoRequest)
    {
        $user = $request->user();
        if (!$user) {
            return redirect('/admin/login');
        }

        if ((int) $logoRequest->user_id !== (int) $user->id) {
            abort(403, 'You do not have access to this logo request.');
        }

        $imageUrls = array_values(array_filter((array) $logoRequest->image_urls, function ($url) {
            return is_string($url) && $url !== '';
        }));

        if (empty($imageUrls)) {
            abort(404, 'No logo images found for this request.');
        }

        $imageIndex = max(0, (int) $request->query('image', 0));
        if (!isset($imageUrls[$imageIndex])) {
            $imageIndex = 0;
        }

        $imageUrl = $imageUrls[$imageIndex];

        // Guard against placeholder URLs from cleaned-up base64 data
        if ($imageUrl === '[base64-omitted]' || empty($imageUrl)) {
            abort(404, 'This logo image is no longer available (data was cleaned up).');
        }

        // Determine if this is a local public-disk file or an external URL
        $binary = null;
        $extension = 'png';
        $mimeType = 'image/png';

        // Handle base64 data URIs
        if (preg_match('/^data:image\/([a-z+]+);base64,(.+)$/si', $imageUrl, $b64Match)) {
            $mimeMap = [
                'png' => 'image/png',
                'jpeg' => 'image/jpeg',
                'jpg' => 'image/jpeg',
                'webp' => 'image/webp',
                'svg+xml' => 'image/svg+xml',
                'gif' => 'image/gif',
            ];
            $subtype = strtolower($b64Match[1]);
            $mimeType = $mimeMap[$subtype] ?? 'image/png';
            $binary = base64_decode($b64Match[2], true);
            if ($binary === false) {
                $binary = null;
            }
        }

        // Check if it's a local /storage/ URL
        if ($binary === null) {
            $parsedPath = parse_url($imageUrl, PHP_URL_PATH);
            if ($parsedPath && str_starts_with($parsedPath, '/storage/')) {
                $relativePath = substr($parsedPath, strlen('/storage/'));
                $fullPath = Storage::disk('public')->path($relativePath);
                if (file_exists($fullPath)) {
                    $binary = file_get_contents($fullPath);
                    $detectedMime = mime_content_type($fullPath);
                    if ($detectedMime) {
                        $mimeType = $detectedMime;
                    }
                }
            }
        }

        // Fallback: fetch from URL (only for http/https URLs)
        if ($binary === null && preg_match('/^https?:\/\//', $imageUrl)) {
            try {
                $response = \Illuminate\Support\Facades\Http::timeout(15)->get($imageUrl);
                if ($response->successful()) {
                    $binary = $response->body();
                    $contentType = $response->header('Content-Type');
                    if ($contentType && str_starts_with($contentType, 'image/')) {
                        $mimeType = explode(';', $contentType)[0];
                    }
                }
            } catch (\Exception $e) {
                abort(500, 'Failed to fetch logo image.');
            }
        }

        if (!$binary) {
            abort(404, 'Could not load logo image.');
        }

        // Determine extension from mime type
        $extMap = [
            'image/svg+xml' => 'svg',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        $extension = $extMap[$mimeType] ?? 'png';

        // Store in documents directory on default disk
        $safeDomain = Str::slug((string) $logoRequest->domain) ?: 'logo';
        $filename = Str::uuid()->toString() . '.' . $extension;
        $storedPath = 'documents/' . $filename;
        Storage::put($storedPath, $binary);

        $originalName = sprintf('%s-logo-%d.%s', $safeDomain, $imageIndex + 1, $extension);

        $document = Document::create([
            'original_name' => $originalName,
            'path' => $storedPath,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($binary),
        ]);

        return redirect()->route('documents.edit', $document);
    }

    public function saveEditedLogo(Request $request, AiLogoRequest $logoRequest)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'You must be logged in.'], 401);
        }

        if ((int) $logoRequest->user_id !== (int) $user->id) {
            return response()->json(['error' => 'You do not have access to this logo request.'], 403);
        }

        $validated = $request->validate([
            'image_data' => ['required', 'string'],
            'image_index' => ['nullable', 'integer', 'min:0'],
        ]);

        $imageData = (string) $validated['image_data'];
        if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,/', $imageData, $matches)) {
            return response()->json(['error' => 'Invalid edited image format.'], 422);
        }

        $extension = $matches[1] === 'jpeg' ? 'jpg' : strtolower($matches[1]);
        $base64 = substr($imageData, strpos($imageData, ',') + 1);
        $binary = base64_decode($base64, true);
        if ($binary === false) {
            return response()->json(['error' => 'Failed to decode edited image.'], 422);
        }

        $imageIndex = (int) ($validated['image_index'] ?? 0);
        $safeDomain = Str::slug((string) $logoRequest->domain) ?: 'logo';
        $filename = sprintf(
            '%s-%d-%02d-edited-%s.%s',
            $safeDomain,
            (int) $logoRequest->id,
            max(1, $imageIndex + 1),
            now()->format('YmdHis'),
            $extension
        );
        $relativePath = sprintf('logos/%d/%d/edited/%s', (int) $user->id, (int) $logoRequest->id, $filename);
        Storage::disk('public')->put($relativePath, $binary);
        $publicUrl = '/storage/' . $relativePath;

        $urls = array_values((array) $logoRequest->image_urls);
        if ($imageIndex >= 0 && $imageIndex < count($urls)) {
            $urls[$imageIndex] = $publicUrl;
        } else {
            $urls[] = $publicUrl;
        }

        $logoRequest->update([
            'storage_type' => 'path',
            'image_urls' => $urls,
        ]);

        return response()->json([
            'success' => true,
            'image_url' => $publicUrl,
            'image_path' => $relativePath,
        ]);
    }

    private function computePromptSimilarity(string $a, string $b): float
    {
        $tokensA = $this->promptTokens($a);
        $tokensB = $this->promptTokens($b);

        if (empty($tokensA) || empty($tokensB)) {
            return 0.0;
        }

        $setA = array_values(array_unique($tokensA));
        $setB = array_values(array_unique($tokensB));
        $inter = array_values(array_intersect($setA, $setB));
        $union = array_values(array_unique(array_merge($setA, $setB)));

        $jaccard = count($union) > 0 ? (count($inter) / count($union)) : 0.0;

        $freqA = array_count_values($tokensA);
        $freqB = array_count_values($tokensB);
        $keys = array_values(array_unique(array_merge(array_keys($freqA), array_keys($freqB))));

        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        foreach ($keys as $k) {
            $va = (float) ($freqA[$k] ?? 0);
            $vb = (float) ($freqB[$k] ?? 0);
            $dot += $va * $vb;
            $magA += $va * $va;
            $magB += $vb * $vb;
        }
        $cosine = ($magA > 0.0 && $magB > 0.0) ? ($dot / (sqrt($magA) * sqrt($magB))) : 0.0;

        $subset = (min(count($setA), count($setB)) > 0)
            ? (count($inter) / min(count($setA), count($setB)))
            : 0.0;

        $strA = implode(' ', $setA);
        $strB = implode(' ', $setB);
        $bigram = $this->charBigramDice($strA, $strB);

        return (0.35 * $cosine) + (0.35 * $jaccard) + (0.20 * $bigram) + (0.10 * $subset);
    }

    private function promptTokens(string $text): array
    {
        $norm = Str::lower($text);
        $norm = preg_replace('/[^a-z0-9\\s]+/u', ' ', $norm) ?? '';
        $parts = preg_split('/\\s+/', trim($norm)) ?: [];

        $stop = [
            'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'with', 'at', 'by', 'from',
            'is', 'are', 'be', 'as', 'it', 'this', 'that', 'logo', 'design', 'icon',
        ];
        $stopMap = array_fill_keys($stop, true);

        $tokens = [];
        foreach ($parts as $p) {
            if ($p === '' || isset($stopMap[$p])) {
                continue;
            }
            // light stemming for plural/suffix variants
            $stem = $p;
            if (strlen($stem) > 4 && str_ends_with($stem, 'es')) {
                $stem = substr($stem, 0, -2);
            } elseif (strlen($stem) > 3 && str_ends_with($stem, 's')) {
                $stem = substr($stem, 0, -1);
            }
            $tokens[] = $stem;
        }
        return $tokens;
    }

    private function charBigramDice(string $a, string $b): float
    {
        $a = preg_replace('/\\s+/', '', Str::lower($a)) ?? '';
        $b = preg_replace('/\\s+/', '', Str::lower($b)) ?? '';
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if (strlen($a) < 2 || strlen($b) < 2) {
            return ($a === $b) ? 1.0 : 0.0;
        }

        $gramsA = [];
        for ($i = 0; $i < strlen($a) - 1; $i++) {
            $gramsA[] = substr($a, $i, 2);
        }
        $gramsB = [];
        for ($i = 0; $i < strlen($b) - 1; $i++) {
            $gramsB[] = substr($b, $i, 2);
        }

        $countA = array_count_values($gramsA);
        $countB = array_count_values($gramsB);
        $shared = 0;
        foreach ($countA as $gram => $cA) {
            $shared += min($cA, $countB[$gram] ?? 0);
        }

        return (2.0 * $shared) / (count($gramsA) + count($gramsB));
    }

    private function storeRemoteLogoImage(int $requestId, int $userId, ?string $domain, string $imageUrl, int $index): ?array
    {
        try {
            $response = Http::timeout(45)->get($imageUrl);
            if (!$response->successful()) {
                \Log::warning('Failed to download generated logo image', [
                    'request_id' => $requestId,
                    'status' => $response->status(),
                    'image_url' => $imageUrl,
                ]);
                return null;
            }

            $contentType = strtolower((string) $response->header('Content-Type', ''));
            $extension = 'png';
            if (str_contains($contentType, 'jpeg') || str_contains($contentType, 'jpg')) {
                $extension = 'jpg';
            } elseif (str_contains($contentType, 'webp')) {
                $extension = 'webp';
            } elseif (str_contains($contentType, 'svg')) {
                $extension = 'svg';
            } elseif (str_contains($contentType, 'png')) {
                $extension = 'png';
            } else {
                $urlPath = strtolower((string) parse_url($imageUrl, PHP_URL_PATH));
                if (preg_match('/\.(png|jpe?g|webp|svg)$/', $urlPath, $m)) {
                    $extension = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                }
            }

            $safeDomain = $domain ? (Str::slug($domain) ?: 'logo') : 'logo';
            $filename = sprintf('%s-%d-%02d.%s', $safeDomain, $requestId, $index, $extension);
            $relativePath = sprintf('logos/%d/%d/%s', $userId, $requestId, $filename);

            Storage::disk('public')->put($relativePath, $response->body());

            return [
                'path' => $relativePath,
                'url' => '/storage/' . $relativePath,
            ];
        } catch (\Throwable $e) {
            \Log::warning('Exception while storing generated logo image', [
                'request_id' => $requestId,
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate a PRO-quality logo using FLUX.1 [pro] v1.1-ultra.
     * Takes the prompt from a Schnell draft and regenerates at production quality.
     */
    public function generateProLogo(Request $request)
    {
        if (!$request->user()) {
            return response()->json([
                'error' => 'You must be logged in to generate PRO logos.',
            ], 401);
        }

        $request->validate([
            'prompt' => 'required|string|max:2000',
            'domain' => 'required|string|max:255',
            'style' => 'required|string|in:professional,fantasy,future,vector',
            'seed' => 'nullable|integer',
        ]);

        $prompt = $request->input('prompt');
        $domain = $request->input('domain');
        $style = $request->input('style');
        $seed = $request->input('seed');

        // Pro cost estimate (~$0.05 per image)
        $costPerImage = 0.05;

        $logoRequest = AiLogoRequest::create([
            'user_id' => $request->user()->id,
            'domain' => $domain,
            'style' => $style . '_pro',
            'model' => 'fal-ai/flux-pro/v1.1-ultra',
            'seed_number' => is_numeric($seed) ? (int) $seed : null,
            'prompt' => $prompt,
            'status' => 'pending',
        ]);

        $priceLog = AiLogoPrice::create([
            'user_id' => $request->user()->id,
            'ai_logo_request_id' => $logoRequest->id,
            'session' => session()->getId(),
            'user_email' => $request->user()->email,
            'request_type' => 'logo_pro',
            'model_name' => 'fal-ai/flux-pro/v1.1-ultra',
            'image_count' => 1,
            'image_size' => 'square_hd',
            'num_inference_steps' => 28,
            'guidance_scale' => 3.50,
            'cost_per_image' => $costPerImage,
            'estimated_cost_usd' => $costPerImage,
            'status' => 'pending',
            'prompt_preview' => substr($prompt, 0, 255),
        ]);

        $startTime = microtime(true);

        try {
            $fluxUltraUrl = 'https://fal.run/fal-ai/flux-pro/v1.1-ultra';
            $response = $this->httpWithResolvedDns($fluxUltraUrl, [
                'Authorization' => 'Key ' . config('services.fal.key'),
                'Content-Type' => 'application/json',
            ])->retry(3, 2000, function (\Exception $e, \Illuminate\Http\Client\PendingRequest $request) {
                return $e instanceof \Illuminate\Http\Client\ConnectionException
                    || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->serverError());
            })->timeout(180)->post($fluxUltraUrl, array_filter([
                'prompt' => $prompt,
                'image_size' => 'square_hd',
                'num_inference_steps' => 28,
                'guidance_scale' => 3.5,
                'safety_tolerance' => 5,
                'seed' => $seed,
                'num_images' => 1,
                'sync_mode' => true,
            ], fn ($v) => !is_null($v)));

            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

            if (!$response->successful()) {
                $falError = $response->json('detail') ?? $response->json('message') ?? 'Unknown error';
                \Log::error('Fal.ai PRO logo generation failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $logoRequest->update([
                    'status' => 'failed',
                    'fal_status_code' => $response->status(),
                    'error_message' => $falError,
                    'response_time_ms' => $elapsedMs,
                ]);

                $priceLog->update([
                    'status' => 'failed',
                    'response_time_ms' => $elapsedMs,
                ]);

                return response()->json([
                    'error' => 'PRO generation failed: ' . $falError,
                ], 500);
            }

            $data = $response->json();
            $images = $data['images'] ?? [];
            $imageUrls = array_map(fn ($img) => $img['url'] ?? $img, $images);

            // Persist PRO images to local storage
            $storedImageUrls = [];
            foreach ($imageUrls as $idx => $imgUrl) {
                if (!$imgUrl || str_starts_with($imgUrl, 'data:')) continue;

                $stored = $this->storeRemoteLogoImage(
                    imageUrl: $imgUrl,
                    requestId: (int) $logoRequest->id,
                    userId: (int) $request->user()->id,
                    domain: $domain,
                    index: $idx + 1
                );

                if ($stored) {
                    $storedImageUrls[] = $stored['url'];
                    if (is_array($images[$idx])) {
                        $images[$idx]['stored_path'] = $stored['path'];
                        $images[$idx]['stored_url'] = $stored['url'];
                    }
                }
            }

            $logoRequest->update([
                'status' => 'completed',
                'fal_status_code' => $response->status(),
                'storage_type' => !empty($storedImageUrls) ? 'path' : 'url',
                'image_urls' => !empty($storedImageUrls) ? $storedImageUrls : $imageUrls,
                'response_time_ms' => $elapsedMs,
            ]);

            $priceLog->update([
                'status' => 'completed',
                'actual_cost_usd' => $costPerImage,
                'response_time_ms' => $elapsedMs,
            ]);

            return response()->json([
                'images' => $images,
                'prompt' => $prompt,
                'cost' => [
                    'image_count' => 1,
                    'cost_per_image' => $costPerImage,
                    'total_cost' => $costPerImage,
                ],
            ]);
        } catch (\Exception $e) {
            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

            $logoRequest->update([
                'status' => 'error',
                'error_message' => $e->getMessage(),
                'response_time_ms' => $elapsedMs,
            ]);

            $priceLog->update([
                'status' => 'error',
                'response_time_ms' => $elapsedMs,
            ]);

            return response()->json([
                'error' => 'PRO generation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upscale a logo: background removal → super-resolution upscale.
     */
    public function upscaleLogo(Request $request)
    {
        if (!$request->user()) {
            return response()->json([
                'error' => 'You must be logged in to upscale logos.',
            ], 401);
        }

        $request->validate([
            'image_url' => 'required|string',
        ]);

        $imageUrl = $request->input('image_url');
        $falKey = config('services.fal.key');

        $startTime = microtime(true);

        try {
            // Step 1: Remove background
            $birefnetUrl = 'https://fal.run/fal-ai/birefnet';
            $bgResponse = $this->httpWithResolvedDns($birefnetUrl, [
                'Authorization' => 'Key ' . $falKey,
                'Content-Type' => 'application/json',
            ])->retry(3, 2000, function (\Exception $e, \Illuminate\Http\Client\PendingRequest $request) {
                return $e instanceof \Illuminate\Http\Client\ConnectionException
                    || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->serverError());
            })->timeout(60)->post($birefnetUrl, [
                'image_url' => $imageUrl,
                'model' => 'General Use (Light)',
                'operating_resolution' => '1024x1024',
                'output_format' => 'png',
            ]);

            if (!$bgResponse->successful()) {
                $bgError = $bgResponse->json('detail') ?? $bgResponse->json('message') ?? 'Unknown error';
                \Log::error('Background removal failed', ['status' => $bgResponse->status(), 'body' => $bgResponse->body()]);
                return response()->json([
                    'error' => 'Background removal failed: ' . $bgError,
                ], 500);
            }

            $bgData = $bgResponse->json();
            $transparentUrl = $bgData['image']['url'] ?? null;

            if (!$transparentUrl) {
                return response()->json([
                    'error' => 'Background removal returned no image.',
                ], 500);
            }

            // Step 2: Upscale with Aura SR (2x sharpening)
            $auraSrUrl = 'https://fal.run/fal-ai/aura-sr';
            $upscaleResponse = $this->httpWithResolvedDns($auraSrUrl, [
                'Authorization' => 'Key ' . $falKey,
                'Content-Type' => 'application/json',
            ])->retry(3, 2000, function (\Exception $e, \Illuminate\Http\Client\PendingRequest $request) {
                return $e instanceof \Illuminate\Http\Client\ConnectionException
                    || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->serverError());
            })->timeout(180)->post($auraSrUrl, [
                'image_url' => $transparentUrl,
                'upscaling_factor' => 2,
            ]);

            if (!$upscaleResponse->successful()) {
                $upError = $upscaleResponse->json('detail') ?? $upscaleResponse->json('message') ?? 'Unknown error';
                \Log::error('Upscale failed', ['status' => $upscaleResponse->status(), 'body' => $upscaleResponse->body()]);
                return response()->json([
                    'error' => 'Upscale failed: ' . $upError,
                ], 500);
            }

            $upscaleData = $upscaleResponse->json();
            $upscaledUrl = $upscaleData['image']['url'] ?? null;

            if (!$upscaledUrl) {
                return response()->json([
                    'error' => 'Upscaler returned no image.',
                ], 500);
            }

            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

            // Log the upscale cost
            AiLogoPrice::create([
                'user_id' => $request->user()->id,
                'session' => session()->getId(),
                'user_email' => $request->user()->email,
                'request_type' => 'logo_upscale',
                'model_name' => 'fal-ai/aura-sr + bria/background-removal',
                'image_count' => 1,
                'image_size' => 'upscaled_4x',
                'num_inference_steps' => 0,
                'guidance_scale' => 0,
                'cost_per_image' => 0.01,
                'estimated_cost_usd' => 0.01,
                'actual_cost_usd' => 0.01,
                'status' => 'completed',
                'prompt_preview' => 'Upscale: bg-removal → aura-sr 4x',
                'response_time_ms' => $elapsedMs,
            ]);

            return response()->json([
                'original_url' => $imageUrl,
                'transparent_url' => $transparentUrl,
                'upscaled_url' => $upscaledUrl,
                'processing_time_ms' => $elapsedMs,
            ]);
        } catch (\Exception $e) {
            \Log::error('Logo upscale error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Upscale failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the background from a generated logo image.
     */
    public function removeLogoBg(Request $request)
    {
        if (!$request->user()) {
            return response()->json([
                'error' => 'You must be logged in to remove backgrounds.',
            ], 401);
        }

        $request->validate([
            'image_url' => 'required|string',
        ]);

        // ── Balance check for background removal ($0.01) ──
        $user = $request->user();
        $bgRemovalCost = 0.01;
        if ((float) $user->credit_balance < $bgRemovalCost) {
            return response()->json([
                'error' => 'Insufficient balance. Background removal costs ~$0.01. Please add credits.',
                'credit_balance' => (float) $user->credit_balance,
            ], 402);
        }

        $imageUrl = $request->input('image_url');
        $startTime = microtime(true);

        try {
            // Download the image so we can upload it as a file to Recraft
            // Handle local storage paths (e.g. /storage/logos/...)
            if (str_starts_with($imageUrl, '/storage/')) {
                $localPath = storage_path('app/public/' . substr($imageUrl, 9));
                if (!file_exists($localPath)) {
                    return response()->json([
                        'error' => 'Source image not found on disk.',
                    ], 500);
                }
                $imageContents = file_get_contents($localPath);
            } elseif (str_starts_with($imageUrl, 'data:')) {
                // Handle base64 data URIs
                $parts = explode(',', $imageUrl, 2);
                $imageContents = base64_decode($parts[1] ?? '');
            } else {
                $imageContents = Http::timeout(30)->get($imageUrl)->body();
            }

            if (!$imageContents) {
                return response()->json([
                    'error' => 'Could not download the source image.',
                ], 500);
            }

            // Determine file extension from URL or default to png
            $ext = 'png';
            if (preg_match('/\.(png|jpg|jpeg|webp|svg)(\?|$)/i', $imageUrl, $m)) {
                $ext = strtolower($m[1]);
            }

            $recraftKey = config('services.recraft.key');
            $recraftBaseUrl = config('services.recraft.base_url', 'https://external.api.recraft.ai');

            $recraftBgUrl = $recraftBaseUrl . '/v1/images/removeBackground';
            $bgResponse = $this->httpWithResolvedDns($recraftBgUrl, [
                'Authorization' => 'Bearer ' . $recraftKey,
            ])->retry(3, 2000, function (\Exception $e, \Illuminate\Http\Client\PendingRequest $request) {
                // Retry on connection errors and 5xx server errors
                return $e instanceof \Illuminate\Http\Client\ConnectionException
                    || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->serverError());
            })->timeout(60)->attach(
                'file', $imageContents, 'logo.' . $ext
            )->post($recraftBgUrl, [
                'response_format' => 'url',
            ]);

            if (!$bgResponse->successful()) {
                $bgError = $bgResponse->json('error.message')
                    ?? $bgResponse->json('detail')
                    ?? $bgResponse->json('message')
                    ?? 'Unknown error (HTTP ' . $bgResponse->status() . ')';
                \Log::error('Recraft background removal failed', [
                    'status' => $bgResponse->status(),
                    'body' => substr($bgResponse->body(), 0, 500),
                ]);
                return response()->json([
                    'error' => 'Background removal failed: ' . $bgError,
                ], 500);
            }

            $bgData = $bgResponse->json();
            $transparentUrl = $bgData['image']['url'] ?? ($bgData['data'][0]['url'] ?? null);

            if (!$transparentUrl) {
                return response()->json([
                    'error' => 'Background removal returned no image.',
                ], 500);
            }

            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

            // Recraft remove_bg costs 10 units = $0.01
            $cost = \App\Services\RecraftPricing::estimate('remove_bg', 'tools')['usd'];

            // Log the cost
            AiLogoPrice::create([
                'user_id' => $request->user()->id,
                'session' => session()->getId(),
                'user_email' => $request->user()->email,
                'request_type' => 'logo_bg_removal',
                'model_name' => 'recraft/removeBackground',
                'image_count' => 1,
                'image_size' => '1024x1024',
                'num_inference_steps' => 0,
                'guidance_scale' => 0,
                'cost_per_image' => $cost,
                'estimated_cost_usd' => $cost,
                'actual_cost_usd' => $cost,
                'status' => 'completed',
                'prompt_preview' => 'Background removal via Recraft',
                'response_time_ms' => $elapsedMs,
            ]);

            // Deduct cost from credit balance
            CreditTransaction::debit(
                userId: $request->user()->id,
                amount: $cost,
                service: 'logo_bg_removal',
                modelName: 'recraft/removeBackground',
                description: 'Logo background removal',
            );

            return response()->json([
                'original_url' => $imageUrl,
                'transparent_url' => $transparentUrl,
                'processing_time_ms' => $elapsedMs,
            ]);
        } catch (\Exception $e) {
            if ($e instanceof \Illuminate\Http\Client\ConnectionException) {
                \Log::error('Background removal connection error - NO CHARGE', [
                    'error' => $e->getMessage(),
                    'user_id' => $request->user()->id,
                ]);
            } else {
                \Log::error('Logo bg removal error: ' . $e->getMessage());
            }
            
            $userMessage = $e instanceof \Illuminate\Http\Client\ConnectionException
                ? 'Unable to connect to the AI service. Please try again in a moment. Your account was not charged.'
                : 'Background removal failed: ' . $e->getMessage();
                
            return response()->json([
                'error' => $userMessage,
            ], 500);
        }
    }

    /**
     * Convert a hex colour to an approximate human-readable name.
     * DALL-E responds much better to named colours than raw hex codes.
     */
    private static function hexToColorName(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        // Named colour map — closest match by Euclidean distance
        $colors = [
            'red'           => [255, 0, 0],
            'dark red'      => [139, 0, 0],
            'crimson'       => [220, 20, 60],
            'orange red'    => [255, 69, 0],
            'orange'        => [255, 165, 0],
            'dark orange'   => [255, 140, 0],
            'gold'          => [255, 215, 0],
            'yellow'        => [255, 255, 0],
            'lime green'    => [50, 205, 50],
            'green'         => [0, 128, 0],
            'dark green'    => [0, 100, 0],
            'emerald green' => [80, 200, 120],
            'teal'          => [0, 128, 128],
            'cyan'          => [0, 255, 255],
            'sky blue'      => [135, 206, 235],
            'blue'          => [0, 0, 255],
            'royal blue'    => [65, 105, 225],
            'navy blue'     => [0, 0, 128],
            'dark blue'     => [0, 0, 139],
            'electric purple'=> [191, 0, 255],
            'purple'        => [128, 0, 128],
            'dark purple'   => [48, 0, 48],
            'magenta'       => [255, 0, 255],
            'hot pink'      => [255, 105, 180],
            'pink'          => [255, 192, 203],
            'brown'         => [139, 69, 19],
            'maroon'        => [128, 0, 0],
            'white'         => [255, 255, 255],
            'light gray'    => [211, 211, 211],
            'gray'          => [128, 128, 128],
            'dark gray'     => [64, 64, 64],
            'black'         => [0, 0, 0],
            'coral'         => [255, 127, 80],
            'salmon'        => [250, 128, 114],
            'olive'         => [128, 128, 0],
            'mint green'    => [152, 255, 152],
            'lavender'      => [230, 230, 250],
            'indigo'        => [75, 0, 130],
            'turquoise'     => [64, 224, 208],
        ];

        $bestName = 'color';
        $bestDist = PHP_INT_MAX;
        foreach ($colors as $name => [$cr, $cg, $cb]) {
            $dist = ($r - $cr) ** 2 + ($g - $cg) ** 2 + ($b - $cb) ** 2;
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $bestName = $name;
            }
        }

        return $bestName;
    }

    /**
     * Programmatically strip the background from an AI-generated SVG.
     *
     * AI-generated SVGs (e.g. Recraft) typically include the background as the
     * first <rect> or <path> child of the root <svg>. This removes that element
     * at zero cost, giving a transparent-background SVG.
     */
    private function removeSvgBackground(string $svgContent): ?string
    {
        try {
            $dom = new \DOMDocument();
            // Suppress warnings from potentially messy SVG markup
            @$dom->loadXML($svgContent);

            $svgElements = $dom->getElementsByTagName('svg');
            if ($svgElements->length === 0) {
                return null;
            }

            $svg = $svgElements->item(0);

            // Get the SVG's viewBox or width/height to determine full-canvas coverage
            $viewBox = $svg->getAttribute('viewBox');
            $svgWidth = $svg->getAttribute('width');
            $svgHeight = $svg->getAttribute('height');

            // Find the first child element (skip text nodes)
            $firstElement = null;
            foreach ($svg->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $firstElement = $child;
                    break;
                }
            }

            if (!$firstElement) {
                return null;
            }

            $shouldRemove = false;

            if ($firstElement->tagName === 'rect') {
                // Check if it's a full-canvas rectangle (background)
                $x = (float) ($firstElement->getAttribute('x') ?: 0);
                $y = (float) ($firstElement->getAttribute('y') ?: 0);
                $w = $firstElement->getAttribute('width');
                $h = $firstElement->getAttribute('height');

                // If rect starts at origin and matches SVG dimensions, it's a background
                if ($x <= 0 && $y <= 0 && $w && $h) {
                    $shouldRemove = true;
                }
            } elseif ($firstElement->tagName === 'path') {
                // Some SVGs use a path for the background — check if it has a fill
                // that looks like a solid background (no stroke, simple fill)
                $d = $firstElement->getAttribute('d');
                $fill = $firstElement->getAttribute('fill');
                // A background path is typically a simple rectangle-like path (M...H...V...Z)
                if ($fill && preg_match('/^[Mm]\s*[\d.\-]+[\s,]+[\d.\-]+\s*[HhVv]/', $d)) {
                    $shouldRemove = true;
                }
            }

            if ($shouldRemove) {
                $svg->removeChild($firstElement);
            }

            $result = $dom->saveXML();
            // Remove XML declaration if present, keep just the SVG
            $result = preg_replace('/^<\?xml[^?]*\?>\s*/i', '', $result);

            return $result;
        } catch (\Exception $e) {
            \Log::warning('SVG background removal parse error', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
