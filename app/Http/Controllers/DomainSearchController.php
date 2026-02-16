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

class DomainSearchController extends Controller
{
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
            \Log::error('Logo describe error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to analyze image.'], 500);
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
            'style' => 'nullable|string|in:professional,fantasy,future,vector',
            'bg_color' => 'nullable|string|max:20',
        ]);

        $estimate = AiLogoPrice::estimateCost(
            imageCount: (int) $request->input('count', 4),
            isPro: (bool) $request->input('pro', false),
            proSize: (int) $request->input('pro_size', 1024),
            style: $request->input('style', 'professional'),
            bgColor: $request->input('bg_color', 'white'),
        );

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
            'style' => 'required|string|in:professional,fantasy,future,vector',
            'count' => 'nullable|integer|min:1|max:4',
            'custom_prompt' => 'required|string|min:2|max:500',
            'pro' => 'nullable|boolean',
            'pro_size' => 'nullable|integer|in:512,1024,1536',
            'icon_only' => 'nullable|boolean',
            'bg_color' => 'nullable|string|max:20',
        ]);

        $iconOnly = (bool) $request->input('icon_only', false);
        $domain = $request->input('domain', '');

        // Domain is required unless icon-only mode
        if (!$iconOnly && !trim($domain)) {
            return response()->json([
                'error' => 'Domain name is required when Text in Logo is enabled.',
            ], 422);
        }

        $style = $request->input('style');
        $imageCount = $request->input('count', 4);
        $customPrompt = $request->input('custom_prompt');
        $isPro = (bool) $request->input('pro', false);
        $proSize = (int) $request->input('pro_size', 1024);
        $bgColor = $request->input('bg_color', 'white');

        // Calculate cost estimate from real fal.ai pricing API
        $costEstimate = AiLogoPrice::estimateCost(
            imageCount: $imageCount,
            isPro: $isPro,
            proSize: $proSize,
            style: $style,
            bgColor: $bgColor,
        );

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

            $stylePrompts = [
                'professional' => "A premium corporate icon mark.{$conceptHint} A single bold geometric symbol. Monolithic, thick lines, emblem style, navy blue and gold color palette. Secure, established, Fortune 500 quality. Clean vector art, centered 1:1 square composition, {$bgInstruction}. No shadows, no 3D effects, no gradients, no cluttered details, avoid photorealism, avoid messy lines. High contrast, professional design, 4k.{$noExtraText}",
                'fantasy' => "An epic fantasy-themed icon mark.{$conceptHint} A single ornate magical symbol. Elven runes, enchanted forest motifs, mythical creatures, ancient scrollwork, Lord of the Rings inspired aesthetics, rich emerald green and antique gold color palette. Clean vector art, centered 1:1 square composition, {$bgInstruction}. No shadows, no 3D effects, no cluttered details, avoid photorealism, avoid messy lines. Epic fantasy design, 4k.{$noExtraText}",
                'future' => "A futuristic sci-fi icon mark.{$conceptHint} A single sleek angular geometric symbol. Holographic elements, circuit board patterns, space-age aesthetics, glowing neon cyan and electric purple color palette, starfield accents, advanced technology motifs. Clean vector art, centered 1:1 square composition, {$bgInstruction}. No cluttered details, avoid photorealism, avoid messy lines. Futuristic sci-fi design, 4k.{$noExtraText}",
                'vector' => "A minimalist flat vector icon mark.{$conceptHint} Simple geometric shapes, solid flat colors, clean precise lines, no gradients, no shadows, no textures, no 3D effects. Centered 1:1 square composition, {$bgInstruction}. SVG-ready, print-ready, scalable design. Avoid photorealism, avoid messy lines, avoid cluttered details. Ultra-clean flat vector art, 4k.{$noExtraText}",
            ];
        } else {
            // With brand name text
            $noExtraText = " The ONLY text in the entire image is \"{$brandUpper}\". Do not add any other words, letters, taglines, slogans, or captions anywhere in the image.";

            $stylePrompts = [
                'professional' => "A premium corporate logo. The centerpiece is the word \"{$brandUpper}\" in an elegant, refined custom serif typeface with perfectly spaced letters.{$customElement} Monolithic, thick lines, emblem style, navy blue and gold color palette. Secure, established, Fortune 500 quality. Clean vector art, centered 1:1 square composition, {$bgInstruction}. No shadows, no 3D effects, no gradients, no cluttered details, avoid photorealism, avoid messy lines. High contrast, professional design, 4k.{$noExtraText}",
                'fantasy' => "An epic fantasy-themed logo. The centerpiece is the word \"{$brandUpper}\" in an ornate, medieval-inspired custom typeface with elegant serifs and decorative flourishes.{$customElement} Elven runes, enchanted forest motifs, mythical creatures, ancient scrollwork, Lord of the Rings inspired aesthetics, rich emerald green and antique gold color palette. Clean vector art, centered 1:1 square composition, {$bgInstruction}. No shadows, no 3D effects, no cluttered details, avoid photorealism, avoid messy lines. Epic fantasy design, 4k.{$noExtraText}",
                'future' => "A futuristic sci-fi logo. The centerpiece is the word \"{$brandUpper}\" in a sleek, angular, cyberpunk-inspired custom typeface with sharp edges and neon accents.{$customElement} Holographic elements, circuit board patterns, space-age aesthetics, glowing neon cyan and electric purple color palette, starfield accents, advanced technology motifs. Clean vector art, centered 1:1 square composition, {$bgInstruction}. No cluttered details, avoid photorealism, avoid messy lines. Futuristic sci-fi design, 4k.{$noExtraText}",
                'vector' => "A minimalist flat vector logo for \"{$brandUpper}\". Simple geometric shapes, solid flat colors, clean precise lines, no gradients, no shadows, no textures, no 3D effects.{$customElement} Bold readable typography, centered 1:1 square composition, {$bgInstruction}. SVG-ready, print-ready, scalable design. Avoid photorealism, avoid messy lines, avoid cluttered details. Ultra-clean flat vector art, 4k.{$noExtraText}",
            ];
        }

        $prompt = $stylePrompts[$style];

        $logoRequest = AiLogoRequest::create([
            'user_id' => $request->user()->id,
            'domain' => $domain,
            'style' => $style . ($isPro ? '_pro' : ''),
            'prompt' => $prompt,
            'original_prompt' => $customPrompt ? trim((string) $customPrompt) : null,
            'status' => 'pending',
        ]);

        // Create price log entry (pending)
        $priceLog = AiLogoPrice::create([
            'user_id' => $request->user()->id,
            'ai_logo_request_id' => $logoRequest->id,
            'session' => session()->getId(),
            'user_email' => $request->user()->email,
            'request_type' => $isPro ? 'logo_pro' : 'logo_generation',
            'model_name' => $isPro ? 'fal-ai/flux-pro/v1.1' : 'fal-ai/flux/schnell',
            'image_count' => $imageCount,
            'image_size' => $isPro ? $proSize . 'x' . $proSize : '512x512',
            'num_inference_steps' => $isPro ? 28 : 8,
            'guidance_scale' => 3.50,
            'cost_per_image' => $costEstimate['cost_per_image'],
            'estimated_cost_usd' => $costEstimate['estimated_cost_usd'],
            'status' => 'pending',
            'prompt_preview' => substr($prompt, 0, 255),
        ]);

        $startTime = microtime(true);

        try {
            $endpoint = $isPro
                ? 'https://fal.run/fal-ai/flux-pro/v1.1'
                : 'https://fal.run/fal-ai/flux/schnell';

            $timeout = $isPro ? 120 : 120;

            if ($isPro) {
                // Flux Pro v1.1 only supports num_images=1, so loop for each image
                $allImages = [];
                for ($i = 0; $i < $imageCount; $i++) {
                    $proResponse = Http::withHeaders([
                        'Authorization' => 'Key ' . config('services.fal.key'),
                        'Content-Type' => 'application/json',
                    ])->timeout($timeout)->post($endpoint, [
                        'prompt' => $prompt,
                        'image_size' => [
                            'width' => $proSize,
                            'height' => $proSize,
                        ],
                        'num_images' => 1,
                        'num_inference_steps' => 28,
                        'safety_tolerance' => 5,
                        'sync_mode' => true,
                    ]);

                    if ($proResponse->successful()) {
                        $proData = $proResponse->json();
                        $proImages = $proData['images'] ?? [];
                        foreach ($proImages as $pImg) {
                            $allImages[] = $pImg;
                        }
                    } else {
                        \Log::warning('PRO image ' . ($i + 1) . ' failed', [
                            'status' => $proResponse->status(),
                            'body' => substr($proResponse->body(), 0, 500),
                        ]);
                    }
                }

                // Build a synthetic response-like structure
                $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);
                $data = ['images' => $allImages, 'seed' => null];
                $responseStatus = count($allImages) > 0 ? 200 : 500;

                if (count($allImages) === 0) {
                    $logoRequest->update([
                        'status' => 'failed',
                        'error_message' => 'All PRO image generations failed',
                        'response_time_ms' => $elapsedMs,
                    ]);
                    $priceLog->update(['status' => 'failed', 'response_time_ms' => $elapsedMs]);
                    return response()->json(['error' => 'Failed to generate PRO logos. Please try again.'], 500);
                }
            } else {
                $response = Http::withHeaders([
                    'Authorization' => 'Key ' . config('services.fal.key'),
                    'Content-Type' => 'application/json',
                ])->timeout($timeout)->post($endpoint, [
                    'prompt' => $prompt,
                    'image_size' => [
                        'width' => 512,
                        'height' => 512,
                    ],
                    'num_images' => $imageCount,
                    'num_inference_steps' => 8,
                    'guidance_scale' => 3.5,
                    'sync_mode' => true,
                ]);

                $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

                if (!$response->successful()) {
                    \Log::error('Fal.ai logo generation failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    $falError = $response->json('detail') ?? $response->json('message') ?? 'Unknown error';

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
                        'error' => 'Failed to generate logo: ' . $falError,
                    ], 500);
                }

                $data = $response->json();
                $responseStatus = $response->status();
            }
            $images = $data['images'] ?? [];
            $seed = $data['seed'] ?? null;

            // Extract URLs for DB storage (keep base64 data URIs temporarily;
            // they'll be redacted by the nightly logos:redact-base64 command)
            $imageUrls = [];
            foreach ($images as &$img) {
                $url = is_array($img) ? ($img['url'] ?? '') : (string) $img;
                $imageUrls[] = $url;
            }
            unset($img);

            // NOTE: Local storage now happens AFTER bg removal & vectorization (see below)

            // If transparent or custom color background, remove background via birefnet
            $needsBgRemoval = ($bgColor === 'transparent' || str_starts_with($bgColor, '#'));
            if ($needsBgRemoval) {
                $falKey = config('services.fal.key');
                foreach ($images as $i => &$img) {
                    $imgUrl = is_array($img) ? ($img['url'] ?? '') : (string) $img;
                    if (!$imgUrl || str_starts_with($imgUrl, 'data:')) continue;

                    try {
                        $bgResponse = Http::withHeaders([
                            'Authorization' => 'Key ' . $falKey,
                            'Content-Type' => 'application/json',
                        ])->timeout(60)->post('https://fal.run/fal-ai/birefnet', [
                            'image_url' => $imgUrl,
                            'model' => 'General Use (Light)',
                            'operating_resolution' => '1024x1024',
                            'output_format' => 'png',
                        ]);

                        if ($bgResponse->successful()) {
                            $bgData = $bgResponse->json();
                            $transparentUrl = $bgData['image']['url'] ?? null;
                            if ($transparentUrl) {
                                if (is_array($img)) {
                                    $img['url'] = $transparentUrl;
                                    $img['transparent'] = true;
                                } else {
                                    $images[$i] = ['url' => $transparentUrl, 'transparent' => true];
                                }
                            }
                        } else {
                            \Log::warning('Background removal failed for image ' . $i, [
                                'status' => $bgResponse->status(),
                            ]);
                        }
                    } catch (\Exception $bgEx) {
                        \Log::warning('Background removal exception for image ' . $i, [
                            'error' => $bgEx->getMessage(),
                        ]);
                    }
                }
                unset($img);
            }

            // If vector style, vectorize each image to SVG
            if ($style === 'vector') {
                foreach ($images as $i => &$img) {
                    $rasterUrl = $img['url'] ?? (is_string($img) ? $img : null);
                    if (!$rasterUrl) continue;

                    try {
                        $svgResponse = Http::withHeaders([
                            'Authorization' => 'Key ' . config('services.fal.key'),
                            'Content-Type' => 'application/json',
                        ])->timeout(120)->post('https://fal.run/fal-ai/recraft/vectorize', [
                            'image_url' => $rasterUrl,
                        ]);

                        if ($svgResponse->successful()) {
                            $svgData = $svgResponse->json();
                            $svgUrl = $svgData['image']['url'] ?? ($svgData['images'][0]['url'] ?? null);
                            if ($svgUrl) {
                                $img['svg_url'] = $svgUrl;
                            }
                        } else {
                            \Log::warning('SVG vectorization failed for image ' . $i, [
                                'status' => $svgResponse->status(),
                                'body' => $svgResponse->body(),
                            ]);
                        }
                    } catch (\Exception $svgEx) {
                        \Log::warning('SVG vectorization exception for image ' . $i, [
                            'error' => $svgEx->getMessage(),
                        ]);
                    }
                }
                unset($img);
            }

            // Persist ALL generated images to local storage AFTER all transformations
            // (bg removal, vectorization). This ensures we store the final processed images.
            $storedImageUrls = [];
            $storedImagePaths = [];
            foreach ($images as $idx => &$img) {
                // For vector logos, prefer the SVG URL
                $imgUrl = null;
                if (is_array($img)) {
                    $imgUrl = $img['svg_url'] ?? $img['url'] ?? '';
                } else {
                    $imgUrl = (string) $img;
                }

                if (!$imgUrl || str_starts_with($imgUrl, 'data:') || $imgUrl === '[base64-omitted]') {
                    continue;
                }

                $stored = $this->storeRemoteLogoImage(
                    imageUrl: $imgUrl,
                    requestId: (int) $logoRequest->id,
                    userId: (int) $request->user()->id,
                    domain: $domain,
                    index: (int) $idx + 1
                );

                if ($stored) {
                    $storedImagePaths[] = $stored['path'];
                    $storedImageUrls[] = $stored['url'];
                    if (is_array($img)) {
                        $img['stored_path'] = $stored['path'];
                        $img['stored_url'] = $stored['url'];
                    } else {
                        $img = [
                            'url' => $imgUrl,
                            'stored_path' => $stored['path'],
                            'stored_url' => $stored['url'],
                        ];
                    }
                }
            }
            unset($img);

            // Attach seed to each image for PRO consistency
            $imagesWithSeed = array_map(function ($img) use ($seed) {
                if (is_array($img)) {
                    $img['seed'] = $seed;
                }
                return $img;
            }, $images);

            $logoRequest->update([
                'status' => 'completed',
                'fal_status_code' => $responseStatus,
                'storage_type' => !empty($storedImagePaths) ? 'path' : 'url',
                'image_urls' => !empty($storedImageUrls) ? $storedImageUrls : $imageUrls,
                'response_time_ms' => $elapsedMs,
            ]);

            // Update price log with actual cost and completion
            $actualImageCount = count($images);
            $actualCost = AiLogoPrice::estimateCost(
                imageCount: $actualImageCount,
                isPro: $isPro,
                proSize: $proSize,
                style: $style,
                bgColor: $bgColor,
            );
            $priceLog->update([
                'status' => 'completed',
                'image_count' => $actualImageCount,
                'actual_cost_usd' => $actualCost['estimated_cost_usd'],
                'response_time_ms' => $elapsedMs,
            ]);

            // Deduct actual cost from user's credit balance
            $totalCost = (float) $actualCost['estimated_cost_usd'];
            if ($totalCost > 0) {
                $breakdown = $actualCost['breakdown'] ?? [];
                CreditTransaction::debit(
                    userId: $request->user()->id,
                    amount: $totalCost,
                    service: 'logo_generation',
                    modelName: $isPro ? 'fal-ai/flux-pro/v1.1' : 'fal-ai/flux/schnell',
                    description: $domain ? "{$actualImageCount} logo(s) for {$domain}" : "{$actualImageCount} icon-only logo(s)",
                    metadata: [
                        'domain' => $domain,
                        'style' => $style,
                        'image_count' => $actualImageCount,
                        'resolution' => $isPro ? "{$proSize}x{$proSize}" : '512x512',
                        'pro' => $isPro,
                        'icon_only' => $iconOnly,
                        'bg_color' => $bgColor,
                        'breakdown' => $breakdown,
                    ],
                );
            }

            return response()->json([
                'logo_request_id' => (int) $logoRequest->id,
                'images' => $imagesWithSeed,
                'prompt' => $prompt,
                'seed' => $seed,
                'bg_color' => $bgColor,
                'cost' => [
                    'image_count' => $actualImageCount,
                    'cost_per_image' => $costEstimate['cost_per_image'],
                    'total_cost' => $actualCost['estimated_cost_usd'],
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
                'error' => 'Logo generation failed: ' . $e->getMessage(),
            ], 500);
        }
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
        $publicUrl = Storage::disk('public')->url($relativePath);

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

    private function storeRemoteLogoImage(int $requestId, int $userId, string $domain, string $imageUrl, int $index): ?array
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

            $safeDomain = Str::slug($domain) ?: 'logo';
            $filename = sprintf('%s-%d-%02d.%s', $safeDomain, $requestId, $index, $extension);
            $relativePath = sprintf('logos/%d/%d/%s', $userId, $requestId, $filename);

            Storage::disk('public')->put($relativePath, $response->body());

            return [
                'path' => $relativePath,
                'url' => Storage::disk('public')->url($relativePath),
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
            $response = Http::withHeaders([
                'Authorization' => 'Key ' . config('services.fal.key'),
                'Content-Type' => 'application/json',
            ])->timeout(180)->post('https://fal.run/fal-ai/flux-pro/v1.1-ultra', array_filter([
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
            $bgResponse = Http::withHeaders([
                'Authorization' => 'Key ' . $falKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://fal.run/fal-ai/birefnet', [
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
            $upscaleResponse = Http::withHeaders([
                'Authorization' => 'Key ' . $falKey,
                'Content-Type' => 'application/json',
            ])->timeout(180)->post('https://fal.run/fal-ai/aura-sr', [
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

        $imageUrl = $request->input('image_url');
        $falKey = config('services.fal.key');

        $startTime = microtime(true);

        try {
            $bgResponse = Http::withHeaders([
                'Authorization' => 'Key ' . $falKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://fal.run/fal-ai/birefnet', [
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

            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

            // Log the cost
            AiLogoPrice::create([
                'user_id' => $request->user()->id,
                'session' => session()->getId(),
                'user_email' => $request->user()->email,
                'request_type' => 'logo_bg_removal',
                'model_name' => 'fal-ai/birefnet',
                'image_count' => 1,
                'image_size' => '1024x1024',
                'num_inference_steps' => 0,
                'guidance_scale' => 0,
                'cost_per_image' => 0.005,
                'estimated_cost_usd' => 0.005,
                'actual_cost_usd' => 0.005,
                'status' => 'completed',
                'prompt_preview' => 'Background removal via birefnet',
                'response_time_ms' => $elapsedMs,
            ]);

            // Deduct cost from credit balance
            CreditTransaction::debit(
                userId: $request->user()->id,
                amount: 0.005,
                service: 'logo_bg_removal',
                modelName: 'fal-ai/birefnet',
                description: 'Logo background removal',
            );

            return response()->json([
                'original_url' => $imageUrl,
                'transparent_url' => $transparentUrl,
                'processing_time_ms' => $elapsedMs,
            ]);
        } catch (\Exception $e) {
            \Log::error('Logo bg removal error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Background removal failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
