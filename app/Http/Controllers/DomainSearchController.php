<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiDomainsJob;
use App\Models\AiDomainRequest;
use App\Models\AiLogoRequest;
use App\Models\Admin;
use App\Models\SavedDomain;
use App\Models\SavedLogoPalette;
use App\Models\LogoGeneratorSetting;
use App\Models\VectorEditorState;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Traits\ResolvesExternalDns;

class DomainSearchController extends Controller
{
    use ResolvesExternalDns;
    private const DEFAULT_TLDS = ['com', 'ai', 'net', 'org'];
    private const GENERATE_CATEGORIES = ['tech', 'fantasy', 'scifi', 'horror', 'romance', 'mtg'];
    private const CATEGORY_RESULT_LIMIT = 10;

    public function __construct()
    {
        // Filament authenticates admins on the "admin" guard.
        // Most controller code uses $request->user(), which reads the default guard.
        // This resolver keeps existing checks working for both web and admin sessions.
        $this->middleware(function ($request, $next) {
            if (!$request->user() && Auth::guard('admin')->check()) {
                $request->setUserResolver(fn () => Auth::guard('admin')->user());
            }

            return $next($request);
        });
    }

    /**
     * Common English stop words and filler words to exclude from domain generation.
     * These are too generic, grammatical, or meaningless as domain names.
     */
    private const STOP_WORDS = [
        // Articles & determiners
        'the', 'a', 'an', 'this', 'that', 'these', 'those', 'all', 'both', 'each',
        'few', 'more', 'most', 'other', 'some', 'such', 'no', 'nor', 'any', 'every',
        // Conjunctions
        'and', 'but', 'or', 'yet', 'for', 'nor', 'so',
        // Prepositions
        'of', 'in', 'to', 'on', 'at', 'by', 'up', 'as', 'if', 'into', 'via',
        'from', 'with', 'about', 'above', 'after', 'along', 'among', 'around',
        'before', 'behind', 'below', 'beneath', 'beside', 'between', 'beyond',
        'down', 'during', 'except', 'inside', 'near', 'off', 'out', 'outside',
        'over', 'past', 'since', 'through', 'throughout', 'under', 'until',
        'upon', 'within', 'without',
        // Pronouns
        'he', 'she', 'it', 'we', 'they', 'his', 'her', 'its', 'our', 'their',
        'him', 'them', 'me', 'my', 'you', 'your', 'who', 'whom', 'which', 'what',
        // Common verbs (too generic)
        'is', 'are', 'was', 'were', 'be', 'been', 'being', 'has', 'have', 'had',
        'do', 'does', 'did', 'will', 'would', 'shall', 'should', 'may', 'might',
        'must', 'can', 'could', 'get', 'got', 'let', 'put', 'set', 'use', 'used',
        'say', 'said', 'see', 'seem', 'seemed', 'know', 'make', 'take', 'come',
        'give', 'find', 'goes', 'look', 'keep', 'call', 'try', 'ask', 'need',
        // Common adjectives too vague for branding
        'new', 'old', 'good', 'bad', 'big', 'small', 'large', 'long', 'high',
        'low', 'great', 'little', 'own', 'right', 'sure', 'real', 'true', 'full',
        'open', 'same', 'just', 'only', 'even', 'back', 'very', 'also', 'well',
        // Adverbs / filler
        'not', 'now', 'then', 'when', 'where', 'how', 'why', 'here', 'there',
        'again', 'once', 'never', 'always', 'often', 'still', 'too', 'much',
        'many', 'own', 'same', 'last', 'next', 'first', 'second', 'rather',
    ];

    public function index(Request $request)
    {
        $tldOptions = $this->getTldOptions();
        $availableTlds = array_column($tldOptions, 'tld');

        $defaultTlds = $this->getDefaultSelectedTlds($availableTlds);

        $remainingAiRequests = 25;
        $remainingFileUploads = 5;
        if (!$request->user()) {
            $ip = $request->ip();
            $remainingAiRequests = \Illuminate\Support\Facades\RateLimiter::remaining('ai-domain-gen:' . $ip, 25);
            $remainingFileUploads = \Illuminate\Support\Facades\RateLimiter::remaining('domain-file-upload:' . $ip, 5);
        }

        $currentUser = $request->user();
        if ($currentUser && $this->isAdmin($currentUser)) {
            if ($this->supportsAdminSavedDomains()) {
                $savedDomains = SavedDomain::where('admin_id', $currentUser->id)->pluck('domain')->all();
            } else {
                $savedDomains = (array) $request->session()->get('admin_saved_domains', []);
            }
        } elseif ($currentUser) {
            $savedDomains = SavedDomain::where('user_id', $currentUser->id)->pluck('domain')->all();
        } else {
            $savedDomains = [];
        }

        return view('domain-search', [
            'tldOptions' => $tldOptions,
            'defaultTlds' => $defaultTlds,
            'remainingAiRequests' => $remainingAiRequests,
            'remainingFileUploads' => $remainingFileUploads,
            'savedDomains' => $savedDomains,
            'canRefreshSavedDomains' => $currentUser && !$this->isAdmin($currentUser),
        ]);
    }

    public function logoGenerator2(Request $request)
    {
        $user = $request->user();
        $settings = $user && Schema::hasTable('logo_generator_settings')
            ? $this->logoGeneratorSettingsForUser($user)
            : [];

        return view('logo-generator-2', [
            'logoUser' => $user,
            'logoGeneratorSettings' => $settings,
        ]);
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
        $domainRequest = $this->startDomainRequestLog(
            $request,
            $this->buildDomainLogPrompt($names, 'check'),
            $tlds
        );

        $check = $this->checkDomainAvailability($names, $tlds);
        $this->completeDomainRequestLog($domainRequest, $names, $tlds, $check['results'] ?? [], $check['error'] ?? null);

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
            'min_length' => 'nullable|integer|min:3|max:14',
            'max_length' => 'nullable|integer|min:3|max:14',
        ]);

        $prefix    = $this->sanitizeDomainLabelPart((string) $request->input('prefix', ''));
        $suffix    = $this->sanitizeDomainLabelPart((string) $request->input('suffix', ''));
        $category  = strtolower((string) $request->input('category'));
        $minLength = (int) $request->input('min_length', 3);
        $maxLength = (int) $request->input('max_length', 12);

        if ($minLength > $maxLength) {
            [$minLength, $maxLength] = [$maxLength, $minLength];
        }

        if ($prefix === '' && $suffix === '') {
            return response()->json([
                'names' => [],
                'error' => 'Enter a prefix or suffix to generate ideas.',
            ], 422);
        }

        // Adjust the word-score length filter to account for prefix + suffix lengths,
        // so the slider represents the total combined name length.
        $affix   = strlen($prefix) + strlen($suffix);
        $wordMin = max(1, $minLength - $affix);
        $wordMax = max(1, $maxLength - $affix);

        $names = $this->generateCategoryDomains($prefix, $suffix, $category, $wordMin, $wordMax);

        return response()->json(['names' => $names, 'error' => null]);
    }

    public function toggleSavedDomain(Request $request)
    {
        $request->validate([
            'domain'        => 'required|string|max:253',
            'is_available'  => 'nullable|boolean',
            'is_premium'    => 'nullable|boolean',
            'premium_price' => 'nullable|numeric|min:0',
        ]);

        $user   = $request->user();
        $domain = strtolower(trim($request->input('domain')));

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $availabilityData = [
            'is_available'  => $request->input('is_available'),
            'is_premium'    => $request->boolean('is_premium', false),
            'premium_price' => $request->input('premium_price'),
            'checked_at'    => now(),
        ];

        // Admins persist favorites via admin_id
        if ($this->isAdmin($user)) {
            if ($this->supportsAdminSavedDomains()) {
                $existing = SavedDomain::where('admin_id', $user->id)
                    ->where('domain', $domain)
                    ->first();

                if ($existing) {
                    $existing->delete();
                    return response()->json(['saved' => false]);
                }

                SavedDomain::create(array_merge([
                    'admin_id' => $user->id,
                    'user_id'  => null,
                    'domain'   => $domain,
                ], $availabilityData));
                return response()->json(['saved' => true]);
            }

            // Fallback for environments where migration hasn't been applied yet
            $saved = (array) $request->session()->get('admin_saved_domains', []);
            $saved = array_values(array_unique(array_map(
                fn ($d) => strtolower(trim((string) $d)),
                $saved
            )));

            if (in_array($domain, $saved, true)) {
                $saved = array_values(array_filter($saved, fn ($d) => $d !== $domain));
                $request->session()->put('admin_saved_domains', $saved);
                return response()->json(['saved' => false]);
            }

            $saved[] = $domain;
            $request->session()->put('admin_saved_domains', array_values(array_unique($saved)));
            return response()->json(['saved' => true]);
        }

        $existing = SavedDomain::where('user_id', $user->id)
            ->where('domain', $domain)
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json(['saved' => false]);
        }

        SavedDomain::create(array_merge([
            'user_id' => $user->id,
            'domain'  => $domain,
        ], $availabilityData));
        return response()->json(['saved' => true]);
    }

    public function listLogoPalettes(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        if (!Schema::hasTable('saved_logo_palettes')) {
            return response()->json(['error' => 'Saved palettes table is missing. Run migrations first.'], 503);
        }

        $query = SavedLogoPalette::query();
        if ($this->isAdmin($user)) {
            $query->where('admin_id', $user->id);
        } else {
            $query->where('user_id', $user->id);
        }

        $palettes = $query
            ->orderBy('name')
            ->orderByDesc('id')
            ->get(['id', 'name', 'colors', 'updated_at'])
            ->map(function (SavedLogoPalette $palette) {
                $colors = collect($palette->colors ?? [])
                    ->map(fn ($color) => strtoupper((string) $color))
                    ->filter(fn ($color) => preg_match('/^#[0-9A-F]{6}$/', $color))
                    ->values()
                    ->all();

                return [
                    'id' => (int) $palette->id,
                    'name' => $palette->name,
                    'colors' => $colors,
                    'updated_at' => optional($palette->updated_at)->toISOString(),
                ];
            })
            ->values();

        return response()->json([
            'palettes' => $palettes,
        ]);
    }

    public function saveLogoPalette(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        if (!Schema::hasTable('saved_logo_palettes')) {
            return response()->json(['error' => 'Saved palettes table is missing. Run migrations first.'], 503);
        }

        $validated = $request->validate([
            'name' => 'required|string|min:1|max:60',
            'colors' => 'required|array|min:2|max:5',
            'colors.*' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $name = trim($validated['name']);
        $colors = collect($validated['colors'])
            ->map(fn ($color) => strtoupper(trim((string) $color)))
            ->values()
            ->all();

        $query = SavedLogoPalette::query()->where('name', $name);
        $attrs = [
            'name' => $name,
            'colors' => $colors,
        ];

        if ($this->isAdmin($user)) {
            $query->where('admin_id', $user->id);
            $attrs['admin_id'] = $user->id;
            $attrs['user_id'] = null;
        } else {
            $query->where('user_id', $user->id);
            $attrs['user_id'] = $user->id;
            $attrs['admin_id'] = null;
        }

        $palette = $query->first();
        if ($palette) {
            $palette->fill($attrs);
            $palette->save();
        } else {
            $palette = SavedLogoPalette::create($attrs);
        }

        return response()->json([
            'saved' => true,
            'palette' => [
                'id' => (int) $palette->id,
                'name' => $palette->name,
                'colors' => $colors,
                'updated_at' => optional($palette->updated_at)->toISOString(),
            ],
        ]);
    }

    public function deleteLogoPalette(Request $request, SavedLogoPalette $palette)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        if (!Schema::hasTable('saved_logo_palettes')) {
            return response()->json(['error' => 'Saved palettes table is missing. Run migrations first.'], 503);
        }

        $isOwner = $this->isAdmin($user)
            ? (int) $palette->admin_id === (int) $user->id
            : (int) $palette->user_id === (int) $user->id;

        if (!$isOwner) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $palette->delete();

        return response()->json([
            'deleted' => true,
        ]);
    }

    public function getLogoGeneratorSettings(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        if (!Schema::hasTable('logo_generator_settings')) {
            return response()->json(['error' => 'Logo generator settings table is missing. Run migrations first.'], 503);
        }

        return response()->json([
            'settings' => $this->logoGeneratorSettingsForUser($user),
        ]);
    }

    public function saveLogoGeneratorSettings(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        if (!Schema::hasTable('logo_generator_settings')) {
            return response()->json(['error' => 'Logo generator settings table is missing. Run migrations first.'], 503);
        }

        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.selected_model' => 'required|string|in:flux,recraft,dalle',
            'settings.logo_count' => 'required|integer|min:1|max:4',
            'settings.logo_domain' => 'nullable|string|max:100',
            'settings.logo_prompt' => 'nullable|string|max:2000',
            'settings.logo_style' => 'required|string|in:' . $this->allowedLogoStylesForValidation(),
            'settings.logo_theme' => 'nullable|string|in:' . $this->allowedLogoThemesForValidation(),
            'settings.logo_color_palette' => 'required|string|max:60',
            'settings.logo_custom_colors' => 'nullable|array|min:2|max:5',
            'settings.logo_custom_colors.*' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.background_color' => 'required|string|max:20',
            'settings.background_custom_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.logo_mode' => 'required|string|in:icon_only,icon_text,text_only',
            'settings.pro_mode' => 'required|boolean',
            'settings.pro_size' => 'required|integer|in:512,1024,1536',
            'settings.detail_level' => 'required|string|in:min,medium,max',
            'settings.shape_container' => 'nullable|string|in:circle,square,hexagon,triangle,pentagon',
            'settings.work_mode' => 'required|string|in:logo,image',
            'settings.output_format' => 'required|string|in:raster,vector',
            'settings.image_format' => 'required|string|in:png,bmp',
            'settings.gen_mode' => 'required|string|in:logo,image',
            'settings.image_size' => 'required|string|in:1:1,16:9,9:16',
        ]);

        $settings = $this->sanitizeLogoGeneratorSettings($validated['settings']);

        $query = LogoGeneratorSetting::query();
        $attrs = ['settings' => $settings];

        if ($this->isAdmin($user)) {
            $query->where('admin_id', $user->id);
            $attrs['admin_id'] = $user->id;
            $attrs['user_id'] = null;
        } else {
            $query->where('user_id', $user->id);
            $attrs['user_id'] = $user->id;
            $attrs['admin_id'] = null;
        }

        $record = $query->first();
        if ($record) {
            $record->fill($attrs)->save();
        } else {
            LogoGeneratorSetting::create($attrs);
        }

        return response()->json([
            'saved' => true,
            'settings' => $settings,
        ]);
    }

    private function logoGeneratorSettingsForUser(object $user): array
    {
        $query = LogoGeneratorSetting::query();
        if ($this->isAdmin($user)) {
            $query->where('admin_id', $user->id);
        } else {
            $query->where('user_id', $user->id);
        }

        $record = $query->first();
        if (!$record) {
            return [];
        }

        return $this->sanitizeLogoGeneratorSettings((array) ($record->settings ?? []));
    }

    private function sanitizeLogoGeneratorSettings(array $settings): array
    {
        $allowedStyles = explode(',', $this->allowedLogoStylesForValidation());
        $style = in_array(($settings['logo_style'] ?? 'default'), $allowedStyles, true)
            ? (string) $settings['logo_style']
            : 'default';

        $theme = in_array(($settings['logo_theme'] ?? ''), explode(',', $this->allowedLogoThemesForValidation()), true)
            ? (string) $settings['logo_theme']
            : '';

        $backgroundColor = (string) ($settings['background_color'] ?? 'white');
        if (!in_array($backgroundColor, ['white', 'none', 'black'], true) && !preg_match('/^#[0-9a-fA-F]{6}$/', $backgroundColor)) {
            $backgroundColor = 'white';
        }

        $customColors = collect($settings['logo_custom_colors'] ?? [])
            ->map(fn ($color) => strtoupper(trim((string) $color)))
            ->filter(fn ($color) => preg_match('/^#[0-9A-F]{6}$/', $color))
            ->values()
            ->take(5)
            ->all();
        if (count($customColors) < 2) {
            $customColors = ['#1E3A5F', '#D4AF37', '#333333'];
        }

        $shape = (string) ($settings['shape_container'] ?? '');
        if (!in_array($shape, ['', 'circle', 'square', 'hexagon', 'triangle', 'pentagon'], true)) {
            $shape = '';
        }

        $backgroundColor = preg_match('/^#[0-9a-fA-F]{6}$/', $backgroundColor)
            ? strtoupper($backgroundColor)
            : strtolower($backgroundColor);

        return [
            'selected_model' => in_array(($settings['selected_model'] ?? 'flux'), ['flux', 'recraft', 'dalle'], true) ? (string) $settings['selected_model'] : 'flux',
            'logo_count' => max(1, min(4, (int) ($settings['logo_count'] ?? 2))),
            'logo_domain' => mb_substr(trim((string) ($settings['logo_domain'] ?? '')), 0, 100),
            'logo_prompt' => mb_substr(trim((string) ($settings['logo_prompt'] ?? '')), 0, 2000),
            'logo_style' => $style,
            'logo_theme' => $theme,
            'logo_color_palette' => mb_substr(trim((string) ($settings['logo_color_palette'] ?? 'none')), 0, 60) ?: 'none',
            'logo_custom_colors' => $customColors,
            'background_color' => $backgroundColor,
            'background_custom_color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($settings['background_custom_color'] ?? ''))
                ? strtoupper((string) $settings['background_custom_color'])
                : '#4F46E5',
            'logo_mode' => in_array(($settings['logo_mode'] ?? 'icon_only'), ['icon_only', 'icon_text', 'text_only'], true) ? (string) $settings['logo_mode'] : 'icon_only',
            'pro_mode' => (bool) ($settings['pro_mode'] ?? true),
            'pro_size' => in_array((int) ($settings['pro_size'] ?? 512), [512, 1024, 1536], true) ? (int) $settings['pro_size'] : 512,
            'detail_level' => in_array(($settings['detail_level'] ?? 'medium'), ['min', 'medium', 'max'], true) ? (string) $settings['detail_level'] : 'medium',
            'shape_container' => $shape,
            'work_mode' => in_array(($settings['work_mode'] ?? 'logo'), ['logo', 'image'], true) ? (string) $settings['work_mode'] : 'logo',
            'output_format' => in_array(($settings['output_format'] ?? 'vector'), ['raster', 'vector'], true) ? (string) $settings['output_format'] : 'vector',
            'image_format' => in_array(($settings['image_format'] ?? 'png'), ['png', 'bmp'], true) ? (string) $settings['image_format'] : 'png',
            'gen_mode' => in_array(($settings['gen_mode'] ?? 'logo'), ['logo', 'image'], true) ? (string) $settings['gen_mode'] : 'logo',
            'image_size' => in_array(($settings['image_size'] ?? '1:1'), ['1:1', '16:9', '9:16'], true) ? (string) $settings['image_size'] : '1:1',
        ];
    }

    public function userLogos(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Fetch ALL user's logo requests (no limit) ordered by most recent
        $logos = AiLogoRequest::where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereNotNull('image_urls')
            ->orderByDesc('created_at')
            ->get();

        $result = [];
        foreach ($logos as $logo) {
            $urls = array_values(array_filter(is_array($logo->image_urls) ? $logo->image_urls : []));
            foreach ($urls as $idx => $url) {
                if (!is_string($url) || $url === '' || $url === '[base64-omitted]') {
                    continue;
                }

                // Parse URL to get just the path
                $parsed = parse_url($url);
                if (isset($parsed['host'], $parsed['path'])) {
                    $url = $parsed['path'];
                }

                $path = strtolower((string) parse_url($url, PHP_URL_PATH));
                $isVector = str_ends_with($path, '.svg')
                    || $logo->output_format === 'vector'
                    || $logo->mime_type === 'image/svg+xml';

                $result[] = [
                    'id' => $logo->id,
                    'image_index' => (int) $idx,
                    'url' => $url,
                    'preview_url' => route('generatedImages.preview', ['logoRequest' => $logo->id, 'index' => $idx]),
                    'original_url' => route('generatedImages.original', ['logoRequest' => $logo->id, 'index' => $idx]),
                    'domain' => $logo->domain,
                    'isVector' => $isVector,
                    'output_format' => $logo->output_format,
                    'mime_type' => $logo->mime_type,
                    'created' => $logo->created_at?->diffForHumans(),
                    'created_at' => $logo->created_at?->timestamp ?? 0,
                ];
            }
        }

        // Sort results by created_at timestamp (most recent first)
        usort($result, function($a, $b) {
            return $b['created_at'] <=> $a['created_at'];
        });

        return response()->json([
            'success' => true,
            'logos' => $result,
        ]);
    }

    public function saveEditorState(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'svg_content' => 'required|string',
            'layers_data' => 'nullable|array',
            'canvas_size' => 'nullable|string|max:50',
        ]);

        // Check how many states the user already has
        $existingCount = VectorEditorState::where('user_id', $user->id)->count();

        if ($existingCount >= 3) {
            // Delete the oldest state
            $oldestState = VectorEditorState::where('user_id', $user->id)
                ->orderBy('created_at', 'asc')
                ->first();
            
            if ($oldestState) {
                $oldestState->delete();
            }
        }

        // Create new state
        $state = VectorEditorState::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'svg_content' => $validated['svg_content'],
            'layers_data' => $validated['layers_data'] ?? null,
            'canvas_size' => $validated['canvas_size'] ?? 'default',
        ]);

        return response()->json([
            'success' => true,
            'state' => [
                'id' => $state->id,
                'name' => $state->name,
                'created_at' => $state->created_at->diffForHumans(),
            ],
        ]);
    }

    public function getEditorStates(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $states = VectorEditorState::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($state) {
                return [
                    'id' => $state->id,
                    'name' => $state->name,
                    'created_at' => $state->created_at->diffForHumans(),
                    'timestamp' => $state->created_at->timestamp,
                ];
            });

        return response()->json([
            'success' => true,
            'states' => $states,
        ]);
    }

    public function loadEditorState(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $state = VectorEditorState::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$state) {
            return response()->json(['error' => 'State not found'], 404);
        }

        return response()->json([
            'success' => true,
            'state' => [
                'id' => $state->id,
                'name' => $state->name,
                'svg_content' => $state->svg_content,
                'layers_data' => $state->layers_data,
                'canvas_size' => $state->canvas_size,
                'created_at' => $state->created_at->diffForHumans(),
            ],
        ]);
    }

    public function updateEditorState(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $state = VectorEditorState::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$state) {
            return response()->json(['error' => 'State not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'svg_content' => 'required|string',
            'layers_data' => 'nullable|array',
            'canvas_size' => 'nullable|string|max:50',
        ]);

        $state->update([
            'name' => $validated['name'],
            'svg_content' => $validated['svg_content'],
            'layers_data' => $validated['layers_data'] ?? null,
            'canvas_size' => $validated['canvas_size'] ?? 'default',
        ]);

        return response()->json([
            'success' => true,
            'state' => [
                'id' => $state->id,
                'name' => $state->name,
                'created_at' => $state->created_at->diffForHumans(),
            ],
        ]);
    }

    public function deleteEditorState(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $state = VectorEditorState::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$state) {
            return response()->json(['error' => 'State not found'], 404);
        }

        $state->delete();

        return response()->json([
            'success' => true,
            'message' => 'State deleted successfully',
        ]);
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
        $domainRequest = $this->startDomainRequestLog(
            $request,
            $this->buildDomainLogPrompt($names, 'check-start'),
            $tlds
        );

        // Namecheap fast-path: single batched HTTP call, return results immediately
        if (config('services.domain_lookup') === 'namecheap') {
            $check = app(NamecheapClient::class)->checkAvailability($names, $tlds);
            $this->completeDomainRequestLog($domainRequest, $names, $tlds, $check['results'] ?? [], $check['error'] ?? null);

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
            'names' => $names,
            'tlds' => $tlds,
            'ai_domain_request_id' => $domainRequest?->id,
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

        if ($done) {
            $this->completeWhoisJobDomainRequestLog($job, $outputFile, $error);
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

    private function startDomainRequestLog(Request $request, string $prompt, array $tlds): ?AiDomainRequest
    {
        $user = $request->user();
        if (!$user || $this->isAdmin($user)) {
            return null;
        }

        $domainRequest = AiDomainRequest::create([
            'user_id' => $user->id,
            'prompt' => $prompt,
        ]);

        $domainRequest->status = 'processing';
        $domainRequest->tlds = array_values(array_unique(array_map(
            fn ($tld) => ltrim(strtolower(trim((string) $tld)), '.'),
            $tlds
        )));
        $domainRequest->model = config('services.domain_lookup') === 'namecheap' ? 'namecheap' : 'whois';
        $domainRequest->save();

        return $domainRequest;
    }

    private function completeDomainRequestLog(?AiDomainRequest $domainRequest, array $names, array $tlds, array $results, ?string $error): void
    {
        if (!$domainRequest) {
            return;
        }

        $payload = [
            'names' => array_values($names),
            'tlds' => array_values($tlds),
            'results' => array_values($results),
            'error' => $error,
        ];

        $domainRequest->status = $error ? 'failed' : 'completed';
        $domainRequest->response = $payload;
        $domainRequest->result_data = json_encode($payload);
        $domainRequest->error_message = $error;
        $domainRequest->save();
    }

    private function completeWhoisJobDomainRequestLog(array $job, string $outputFile, ?string $error): void
    {
        $requestId = (int) ($job['ai_domain_request_id'] ?? 0);
        if ($requestId <= 0) {
            return;
        }

        $domainRequest = AiDomainRequest::find($requestId);
        if (!$domainRequest) {
            return;
        }

        if (in_array((string) $domainRequest->status, ['completed', 'failed'], true) && $domainRequest->result_data) {
            return;
        }

        $results = [];
        if (file_exists($outputFile)) {
            $lines = file($outputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $parsed = json_decode(trim((string) $line), true);
                if (is_array($parsed) && isset($parsed['domain'])) {
                    $results[] = $parsed;
                }
            }
        }

        $this->completeDomainRequestLog(
            $domainRequest,
            is_array($job['names'] ?? null) ? $job['names'] : [],
            is_array($job['tlds'] ?? null) ? $job['tlds'] : [],
            $results,
            $error
        );
    }

    private function buildDomainLogPrompt(array $names, string $mode): string
    {
        $names = array_values(array_filter(array_map(fn ($name) => strtolower(trim((string) $name)), $names)));
        $preview = implode(', ', array_slice($names, 0, 20));
        if (count($names) > 20) {
            $preview .= ', ...';
        }

        return '[' . $mode . '] ' . $preview;
    }

    /**
     * Generate domain names using AI based on user prompt.
     */
    public function aiGenerate(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            $ip = $request->ip();
            $key = 'ai-domain-gen:' . $ip;
            
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 25)) {
                return response()->json([
                    'error' => 'Unlimited domain search with account, otherwise 25 free AI Generator requests per day',
                    'domains' => [],
                    'authenticated' => false,
                ], 429);
            }
            
            \Illuminate\Support\Facades\RateLimiter::hit($key, 86400); // 24 hours
            
            // Also update the count in the db sessions table
            $sessionId = $request->session()->getId();
            if ($sessionId) {
                try {
                    \Illuminate\Support\Facades\DB::table('sessions')
                        ->where('id', $sessionId)
                        ->increment('free_domain_requests');
                } catch (\Exception $e) {
                    // Ignore if sessions table is not used or doesn't exist
                }
            }
        }

        $request->validate([
            'prompt' => 'required|string|min:3|max:4000',
            'tlds' => 'nullable|array',
            'tlds.*' => ['required', Rule::in($this->getAvailableTlds())],
            'prompt_modifier' => 'nullable|string|in:none,phonetic,numbers',
            'excluded' => 'nullable|array|max:100',
            'excluded.*' => 'nullable|string|max:100',
        ]);

        $prompt = $request->input('prompt');
        $tlds = $request->input('tlds', $this->getDefaultSelectedTlds());
        $promptModifier = strtolower((string) $request->input('prompt_modifier', 'none'));
        $excluded = collect($request->input('excluded', []))
            ->map(fn ($name) => strtolower(trim((string) $name)))
            ->filter(fn ($name) => $name !== '')
            ->map(fn ($name) => preg_replace('/[^a-z0-9-]/', '', $name))
            ->filter(fn ($name) => $name !== '')
            ->values()
            ->all();
        if (!is_array($tlds) || count($tlds) === 0) {
            $tlds = $this->getDefaultSelectedTlds();
        }

        $jobId = Str::uuid()->toString();
        $ownerToken = $this->resolveAiJobOwnerToken($request);

        Cache::put('ai-domain-job:' . $jobId, [
            'status' => 'pending',
            'done' => false,
            'owner' => $ownerToken,
            'queued_at' => now()->toISOString(),
            'user_id' => $user?->id,
        ], now()->addMinutes(30));

        $domainRequestId = null;
        if ($user) {
            $domainRequest = AiDomainRequest::create([
                'user_id' => $user->id,
                'prompt' => $prompt,
            ]);
            $domainRequest->tlds = $tlds;
            $domainRequest->save();
            $domainRequestId = (int) $domainRequest->id;
        }

        GenerateAiDomainsJob::dispatch($jobId, $prompt, $tlds, $promptModifier, $excluded, $user?->id, $domainRequestId);

        return response()->json([
            'job_id' => $jobId,
            'status' => 'pending',
            'queued' => true,
        ], 202);
    }

    public function aiStatus(Request $request, string $jobId)
    {
        if (!preg_match('/^[a-f0-9\-]{36}$/i', $jobId)) {
            return response()->json(['error' => 'Invalid job ID.'], 400);
        }

        $cacheKey = 'ai-domain-job:' . $jobId;
        $state = Cache::get($cacheKey);

        if (!$state) {
            return response()->json(['error' => 'Job not found or expired.'], 404);
        }

        $ownerToken = $this->resolveAiJobOwnerToken($request);
        if (($state['owner'] ?? null) !== $ownerToken) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json([
            'status' => $state['status'] ?? 'pending',
            'done' => (bool) ($state['done'] ?? false),
            'domains' => $state['domains'] ?? [],
            'results' => $state['results'] ?? [],
            'model' => $state['model'] ?? null,
            'usage' => $state['usage'] ?? null,
            'error' => $state['error'] ?? null,
        ]);
    }

    private function resolveAiJobOwnerToken(Request $request): string
    {
        $user = $request->user();
        if ($user) {
            return 'user:' . $user->id;
        }

        return 'guest:' . $request->ip() . ':' . $request->session()->getId();
    }

    private function supportsAdminSavedDomains(): bool
    {
        try {
            return Schema::hasColumn('saved_domains', 'admin_id');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function recordFileUpload(Request $request)
    {
        // Logged-in users have unlimited file uploads
        if ($request->user()) {
            return response()->json(['allowed' => true, 'remaining' => null]);
        }

        $key = 'domain-file-upload:' . $request->ip();

        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'allowed'   => false,
                'remaining' => 0,
                'error'     => 'You have used all 5 free file uploads for today. Log in for unlimited uploads.',
            ], 429);
        }

        \Illuminate\Support\Facades\RateLimiter::hit($key, 86400); // 24-hour window
        $remaining = \Illuminate\Support\Facades\RateLimiter::remaining($key, 5);

        return response()->json(['allowed' => true, 'remaining' => $remaining]);
    }

    public function savedDomainsRefreshStatus(Request $request)
    {
        $user = $request->user();
        if (!$user || $this->isAdmin($user)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json($this->getSavedDomainsRefreshStatus($user));
    }

    public function refreshSavedDomains(Request $request)
    {
        $user = $request->user();
        if (!$user || $this->isAdmin($user)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $status = $this->getSavedDomainsRefreshStatus($user);
        if (!($status['allowed'] ?? false)) {
            return response()->json([
                'error' => 'Refresh is on cooldown.',
                'cooldown' => $status,
            ], 429);
        }

        $domains = SavedDomain::query()
            ->where('user_id', $user->id)
            ->pluck('domain')
            ->filter()
            ->map(fn ($d) => strtolower(trim((string) $d)))
            ->unique()
            ->values()
            ->all();

        if (empty($domains)) {
            return response()->json([
                'error' => 'No favorited domains to refresh.',
            ], 422);
        }

        try {
            $results = app(NamecheapClient::class)->checkFqdns($domains);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Namecheap refresh failed: ' . $e->getMessage(),
            ], 500);
        }

        $rows = collect($results['results'] ?? [])
            ->keyBy(fn ($item) => strtolower((string) ($item['domain'] ?? '')));

        foreach ($domains as $domain) {
            $row = $rows->get($domain);
            if (!$row) {
                continue;
            }

            SavedDomain::query()
                ->where('user_id', $user->id)
                ->whereRaw('LOWER(domain) = ?', [$domain])
                ->update([
                    'is_available' => (bool) ($row['available'] ?? false),
                    'is_premium' => (bool) ($row['premium'] ?? false),
                    'premium_price' => isset($row['premium_price']) ? $row['premium_price'] : null,
                    'checked_at' => now(),
                ]);
        }

        $cooldown = $this->setSavedDomainsRefreshCooldown($user);

        return response()->json([
            'ok' => true,
            'refreshed' => count($domains),
            'cooldown' => $cooldown,
        ]);
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

    private function savedDomainsRefreshCooldownKey(int $userId): string
    {
        return 'saved-domains:refresh:user:' . $userId;
    }

    private function getSavedDomainsRefreshStatus(object $user): array
    {
        $nextAllowedAt = Cache::get($this->savedDomainsRefreshCooldownKey((int) $user->id));
        if (!$nextAllowedAt) {
            return [
                'allowed' => true,
                'cooldown_seconds' => 0,
                'next_available_at' => null,
            ];
        }

        try {
            $next = \Illuminate\Support\Carbon::parse((string) $nextAllowedAt);
        } catch (\Throwable $e) {
            Cache::forget($this->savedDomainsRefreshCooldownKey((int) $user->id));
            return [
                'allowed' => true,
                'cooldown_seconds' => 0,
                'next_available_at' => null,
            ];
        }

        if (now()->gte($next)) {
            Cache::forget($this->savedDomainsRefreshCooldownKey((int) $user->id));
            return [
                'allowed' => true,
                'cooldown_seconds' => 0,
                'next_available_at' => null,
            ];
        }

        return [
            'allowed' => false,
            'cooldown_seconds' => now()->diffInSeconds($next),
            'next_available_at' => $next->toISOString(),
        ];
    }

    private function setSavedDomainsRefreshCooldown(object $user): array
    {
        $next = now()->addHour();
        Cache::put($this->savedDomainsRefreshCooldownKey((int) $user->id), $next->toISOString(), $next);

        return [
            'allowed' => false,
            'cooldown_seconds' => 3600,
            'next_available_at' => $next->toISOString(),
        ];
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

    private function generateCategoryDomains(string $prefix, string $suffix, string $category, int $minLength = 3, int $maxLength = 12): array
    {
        $column = 'category_' . $category;

        $baseQuery = fn() => DB::table('word_scores')
            ->select('word', $column)
            ->whereNotNull('word')
            ->where($column, '>', 0)
            ->whereNotIn('word', self::STOP_WORDS)
            ->orderByDesc($column)
            ->limit(100);

        // First pass: prefer words within the requested length range
        $rows = $baseQuery()
            ->whereRaw('CHAR_LENGTH(word) >= ?', [$minLength])
            ->whereRaw('CHAR_LENGTH(word) <= ?', [$maxLength])
            ->get()
            ->shuffle();

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
            'image_url' => 'required|string|max:2000',
        ]);

        $imageUrl = $request->input('image_url');

        try {
            $apiKey = config('services.gemini.api_key');
            $model = config('services.gemini.model', 'gemini-2.5-flash-lite');
            $baseUrl = config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');

            // Fetch the image — support local storage paths as well as external URLs
            if (str_starts_with($imageUrl, '/storage/')) {
                $localPath = storage_path('app/public/' . substr($imageUrl, 9));
                if (!file_exists($localPath)) {
                    return response()->json(['error' => 'Source image not found on disk.'], 422);
                }
                $imageBytes = file_get_contents($localPath);
                $ext = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
                $mimeType = match($ext) {
                    'webp' => 'image/webp',
                    'png'  => 'image/png',
                    'jpg', 'jpeg' => 'image/jpeg',
                    'svg'  => 'image/svg+xml',
                    default => 'image/png',
                };
            } else {
                $imageData = Http::timeout(15)->get($imageUrl);
                if (!$imageData->successful()) {
                    return response()->json(['error' => 'Could not fetch the image.'], 422);
                }
                $imageBytes = $imageData->body();
                $mimeType = $imageData->header('Content-Type') ?: 'image/png';
            }

            $base64Image = base64_encode($imageBytes);

            $response = Http::timeout(30)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post(
                "{$baseUrl}/models/{$model}:generateContent",
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
            $this->debitUserBalance(
                $request->user(),
                0.0001,
                'logo_describe',
                $model,
                'AI logo analysis (Gemini Vision)',
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
        // Force JSON responses for this endpoint
        $request->headers->set('Accept', 'application/json');
        
        try {
            $request->validate([
                'count' => 'nullable|integer|min:1|max:4',
                'pro' => 'nullable|boolean',
                'pro_size' => 'nullable|integer|in:512,1024,1536',
                'style' => 'nullable|string|in:' . $this->allowedLogoStylesForValidation(),
                'bg_color' => 'nullable|string|max:20',
                'image_model' => 'nullable|string|in:flux,dalle,recraft',
                'output_format' => 'nullable|string|in:raster,vector',
                'image_format' => 'nullable|string|in:png,bmp',
                'recraft_substyle' => 'nullable|string|max:60',
                'gen_mode' => 'nullable|string|in:logo,image',
                'image_size' => 'nullable|string|in:1:1,16:9,9:16',
            ]);

            $imageModel = $request->input('image_model', 'flux');
            $outputFormat = $request->input('output_format', 'raster');
            $genMode = $request->input('gen_mode', 'logo');
            $genImageSize = $request->input('image_size') ?: '1:1';
            $isPro = (bool) $request->input('pro', false);

            if ($this->isUnsupportedRecraftImageSize($imageModel, $outputFormat, $isPro, $genImageSize)) {
                return response()->json([
                    'error' => 'Ray PRO currently supports Square image size only. Landscape and Portrait are not available for this model.',
                ], 422);
            }

        if ($imageModel === 'recraft') {
            $recraftSize = $this->recraftRequestSize($outputFormat, $isPro, $genImageSize);
            $estimate = \App\Services\RecraftPricing::estimateLogoCost(
                imageCount: (int) $request->input('count', 4),
                size: $recraftSize,
                isPro: $isPro,
                type: $outputFormat,
            );
        } elseif ($imageModel === 'dalle') {
            $estimate = AiLogoPrice::estimateDalleCost(
                imageCount: (int) $request->input('count', 4),
                resolution: AiLogoPrice::gptImageResolutionForSize($genImageSize),
                quality: (bool) $request->input('pro', false) ? 'hd' : 'standard',
                outputFormat: $outputFormat,
                bgColor: $request->input('bg_color', 'white'),
            );
        } else {
            $estimate = AiLogoPrice::estimateCost(
                imageCount: (int) $request->input('count', 4),
                isPro: (bool) $request->input('pro', false),
                proSize: (int) $request->input('pro_size', 1024),
                style: $request->input('style', 'professional'),
                bgColor: $request->input('bg_color', 'white'),
                outputFormat: $outputFormat,
                imageModel: $imageModel,
            );
        }

        // Include user's current balance in the estimate response
        $user = $request->user();
        $estimate['credit_balance'] = $user ? (float) $user->credit_balance : 0;
        unset($estimate['base_cost_total'], $estimate['markup_amount']);

        return response()->json($this->publicEstimatePayload($estimate));
        
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Logo price estimation failed', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'An unexpected error occurred.',
            ], 500);
        }
    }

    private function allowedLogoStylesForValidation(): string
    {
        return 'default,professional,fantasy,future,retro,chrome,8bit,dotmatrix,lego,minimalist,greetingcard,photorealistic,minimal_geometric,abstract,monoline,negative_space,tech_gradient,skyline_swoosh,evergreen_silhouette,nature_landscape,modern_sans,bold_geometric,elegant_serif,script_signature,tech_mono,minimal_light';
    }

    private function allowedLogoThemesForValidation(): string
    {
        return 'real_estate,nature,fantasy,technology';
    }

    private function buildLogoPromptPayload(
        ?string $domain,
        string $style,
        string $genMode,
        string $genImageSize,
        bool $iconOnly,
        bool $textOnly,
        ?string $customPrompt,
        ?string $logoTheme,
        string $bgColor,
        string $imageModel,
        string $outputFormat,
        ?array $colorPalette,
        ?string $logoShape,
        ?string $logoDetail,
        ?string $fontStyle,
    ): array {
        $prompt = $this->buildLogoPromptText(
            domain: $domain,
            style: $style,
            genMode: $genMode,
            genImageSize: $genImageSize,
            iconOnly: $iconOnly,
            textOnly: $textOnly,
            customPrompt: $customPrompt,
            logoTheme: $logoTheme,
            bgColor: $bgColor,
            imageModel: $imageModel,
            outputFormat: $outputFormat,
            colorPalette: $colorPalette,
            logoShape: $logoShape,
            logoDetail: $logoDetail,
            fontStyle: $fontStyle,
        );

        return [
            'prompt' => $prompt,
        ];
    }

    private function buildLogoPromptText(
        ?string $domain,
        string $style,
        string $genMode,
        string $genImageSize,
        bool $iconOnly,
        bool $textOnly,
        ?string $customPrompt,
        ?string $logoTheme,
        string $bgColor,
        string $imageModel,
        string $outputFormat,
        ?array $colorPalette,
        ?string $logoShape,
        ?string $logoDetail,
        ?string $fontStyle,
    ): string {
        $brandName = $domain ? preg_replace('/\.(com|net|org|io|co|ai|app|dev|xyz|tech|me)$/i', '', $domain) : '';
        $brandUpper = $brandName ? strtoupper($brandName) : '';
        $customPrompt = trim((string) $customPrompt);
        $logoDetail = $logoDetail ?: 'max';
        $logoShape = $logoShape ?: 'none';
        $fontStyle = $fontStyle ?: 'modern_sans';

        if ($genMode === 'logo' && !$textOnly) {
            if ($logoTheme === 'real_estate' && $style === 'default' && $outputFormat === 'vector') {
                $style = $this->realEstatePromptRequestsRooflineStyle($customPrompt)
                    ? 'skyline_swoosh'
                    : 'real_estate_home';
                $logoDetail = 'min';
            } elseif ($logoTheme === 'nature' && $style === 'default' && $imageModel !== 'dalle') {
                $style = 'nature_landscape';
                $logoDetail = 'min';
            }
        }

        $themeInstruction = $this->logoThemeInstruction($logoTheme);
        if ($logoTheme === 'nature') {
            $seasonInstruction = $this->natureSeasonInstruction($customPrompt);
            if ($seasonInstruction !== '') {
                $themeInstruction = trim($themeInstruction . ' ' . $seasonInstruction);
            }
        }

        $customElement = '';
        if ($customPrompt !== '') {
            $cleaned = preg_replace('/\b[A-Z]{3,}\b/', '', $customPrompt);
            $cleaned = preg_replace('/^(?:generate|create|make|design|draw)\s+(?:a\s+|an\s+|the\s+)?/i', '', $cleaned);
            $cleaned = preg_replace('/^(?:[\w-]+\s+){0,6}logo\s+(?:for|of|featuring|with)\s+/i', '', $cleaned);
            $cleaned = preg_replace('/\b(make|put|add|write|show|display|include|type|spell)\s+(it|the|a|an)?\s*/i', '', $cleaned);
            $cleaned = preg_replace('/\b(large|big|huge|small|tiny|giant|massive|enormous)\b/i', '', $cleaned);
            $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));

            if ($cleaned) {
                $customElement = $style === 'photorealistic'
                    ? " The image features {$cleaned}. Do not render any words or letters from this description."
                    : " A visual icon element of {$cleaned} is integrated into the logo design. Do not render any words or letters from this description.";
            }
        }
        if ($themeInstruction !== '') {
            $customElement .= ' ' . $themeInstruction;
        }

        if (!empty($colorPalette) && is_array($colorPalette)) {
            $colorNames = implode(', ', $colorPalette);
            if ($genMode === 'image') {
                $colorInstruction = match ($logoDetail) {
                    'min' => "Use this palette as flat solid fills and simple accents: {$colorNames}. Avoid gradients, painterly shading, cinematic lighting, heavy shadows, atmospheric color, and extra material colors.",
                    'medium' => "Use this palette as the primary color grade and accent direction: {$colorNames}. Keep lighting, shadows, highlights, texture, and material colors restrained and moderate.",
                    default => "Use this palette as the primary color grade and accent direction: {$colorNames}. Allow natural lighting, shadows, highlights, atmospheric color, and material colors needed for a believable image.",
                };
            } else {
                $colorInstruction = "MANDATORY COLOR PALETTE - use ONLY these exact colors for ALL logo artwork: {$colorNames}. Apply these colors strictly. Do not introduce any other colors. The background color is separate and defined below.";
            }
        } else {
            $colorInstruction = null;
        }

        $bgInstruction = match($bgColor) {
            'none' => '',
            'black' => 'isolated on a solid black background',
            'transparent' => 'isolated on a plain transparent background with no background elements',
            default => str_starts_with($bgColor, '#')
                ? "isolated on a solid {$bgColor} colored background"
                : 'isolated on a solid white background',
        };

        if ($style === 'default') {
            $prompt = $this->buildDefaultLogoPrompt(
                brandUpper: $brandUpper,
                iconOnly: $iconOnly,
                textOnly: $textOnly,
                concept: $customElement,
                colorInstruction: $colorInstruction,
                bgInstruction: $bgInstruction,
                outputFormat: $outputFormat,
                logoShape: $logoShape,
            );
        } else {
            $prompt = \App\Services\FluxPromptBuilder::build(
                style: $style,
                iconOnly: $iconOnly,
                textOnly: $textOnly,
                concept: $customElement,
                colorInstruction: $colorInstruction,
                bgInstruction: $bgInstruction,
                brandUpper: $brandUpper,
                outputFormat: $outputFormat,
                detail: $logoDetail,
                logoShape: $logoShape,
                fontStyle: $fontStyle,
            );

            if ($imageModel === 'dalle') {
                $dalleDesc = trim($customPrompt . ' ' . $themeInstruction);

                if (!empty($colorPalette) && is_array($colorPalette)) {
                    $namedColors = [];
                    foreach ($colorPalette as $hex) {
                        $namedColors[] = $hex . ' (' . self::hexToColorName($hex) . ')';
                    }
                    $dalleColorList = implode(', ', $namedColors);
                } else {
                    $dalleColorList = \App\Services\DallePromptBuilder::defaultColors($style);
                }

                $dalleBg = match($bgColor) {
                    'none' => '',
                    'black' => 'solid black',
                    'white' => 'solid white',
                    'transparent' => 'transparent',
                    default => str_starts_with($bgColor, '#') ? "solid {$bgColor}" : 'solid white',
                };

                $chromeBg = match($bgColor) {
                    'none' => '',
                    'black' => 'dark black background',
                    'white' => 'pure white background',
                    default => str_starts_with($bgColor, '#')
                        ? "{$bgColor} background"
                        : 'soft light gray background',
                };

                $prompt = \App\Services\DallePromptBuilder::build(
                    style: $style,
                    iconOnly: $iconOnly,
                    textOnly: $textOnly,
                    subject: $dalleDesc,
                    brandUpper: $brandUpper,
                    colorList: $dalleColorList,
                    bgInstruction: $dalleBg,
                    chromeBg: $chromeBg,
                    logoShape: $logoShape,
                    detail: $logoDetail,
                    fontStyle: $fontStyle,
                );
            }

            if ($imageModel === 'recraft') {
                $subject = trim($customPrompt . ' ' . $themeInstruction);
                if ($logoTheme === 'real_estate' && $style === 'skyline_swoosh' && $customPrompt !== '') {
                    $subject = $customPrompt;
                } elseif ($logoTheme === 'nature' && $style === 'nature_landscape' && $customPrompt !== '') {
                    $seasonInstruction = $this->natureSeasonInstruction($customPrompt);
                    $subject = trim($customPrompt . ' ' . $seasonInstruction);
                } elseif ($customPrompt === '' && $logoTheme === 'real_estate' && $style === 'real_estate_home') {
                    $subject = 'modern property or real estate symbol using building silhouettes, window grids, doors, keys, map pins, land parcels, or skyline geometry';
                } elseif ($customPrompt === '' && $logoTheme === 'nature' && $style === 'nature_landscape') {
                    $subject = 'trees, forest silhouettes, hills, leaves, branches, and outdoor landscape forms';
                }
                $subject = $subject !== '' ? $subject : ($iconOnly ? 'Abstract geometric symbol' : 'Emblem mark');
                $colorDesc = (!empty($colorPalette) && is_array($colorPalette))
                    ? implode(', ', $colorPalette)
                    : 'AI Picks';

                $bgDesc = match($bgColor) {
                    'none' => '',
                    'black' => '#000000',
                    'transparent' => 'transparent',
                    default => str_starts_with($bgColor, '#') ? $bgColor : '#FFFFFF',
                };

                $prompt = \App\Services\RecraftPromptBuilder::build(
                    style: $style,
                    logoDetail: $logoDetail,
                    logoShape: $logoShape,
                    iconOnly: $iconOnly,
                    textOnly: $textOnly,
                    subject: $subject,
                    brandUpper: $brandUpper,
                    colorDesc: $colorDesc,
                    bgDesc: $bgDesc,
                    outputFormat: $outputFormat,
                    fontStyle: $fontStyle,
                );
            }
        }

        if ($genMode === 'image') {
            $imageBg = match($bgColor) {
                'none' => 'isolated cutout on a transparent background with no sky, terrain, landscape, city, room, scenery, horizon line, ground plane, or full scene; only the requested subject, action shapes, silhouettes, and effects',
                'black' => 'set against a solid black background',
                'transparent' => 'isolated cutout on a transparent background with no sky, terrain, landscape, city, room, scenery, horizon line, ground plane, or full scene; only the requested subject, action shapes, silhouettes, and effects',
                default => str_starts_with($bgColor, '#')
                    ? "set against a solid {$bgColor} background"
                    : '',
            };

            $subject = trim($customPrompt . ' ' . $themeInstruction);
            $prompt = $style === 'default'
                ? $this->buildDefaultImagePrompt(
                    subject: $subject,
                    colorInstruction: $colorInstruction,
                    bgInstruction: $imageBg,
                    imageSize: $genImageSize,
                    logoShape: $logoShape,
                )
                : \App\Services\ImagePromptBuilder::build(
                    style: $style,
                    subject: $subject,
                    colorInstruction: $colorInstruction,
                    bgInstruction: $imageBg,
                    imageSize: $genImageSize,
                    detail: $logoDetail,
                    logoShape: $logoShape,
                );
        }

        return trim($prompt);
    }

    private function buildDefaultLogoPrompt(
        string $brandUpper,
        bool $iconOnly,
        bool $textOnly,
        string $concept,
        ?string $colorInstruction,
        string $bgInstruction,
        string $outputFormat,
        ?string $logoShape,
    ): string {
        if ($textOnly && $brandUpper !== '') {
            $parts = ["Wordmark logo for \"{$brandUpper}\"."];
        } elseif ($iconOnly) {
            $parts = ['Logo mark.'];
        } elseif ($brandUpper !== '') {
            $parts = ["Logo for \"{$brandUpper}\"."];
        } else {
            $parts = ['Logo design.'];
        }

        $concept = trim($concept);
        if ($concept !== '') {
            $parts[] = $concept;
        }

        $parts[] = $outputFormat === 'vector'
            ? 'Create SVG-ready vector artwork with balanced composition, clean edges, and readable forms.'
            : 'Create clear logo artwork with balanced composition and readable forms.';

        $parts[] = $colorInstruction ?: 'Use colors that fit the subject and brand.';

        if ($bgInstruction !== '') {
            $parts[] = rtrim($bgInstruction, '.') . '.';
        }

        if (!empty($logoShape) && $logoShape !== 'none') {
            $shape = strtolower($logoShape);
            $parts[] = "Keep the complete design contained inside a {$shape} shape.";
        }

        if ($textOnly) {
            $parts[] = $brandUpper !== ''
                ? "Text only. Use the brand name exactly as \"{$brandUpper}\". Do not add icons or symbols."
                : 'Text only. Do not add icons or symbols.';
        } elseif ($iconOnly) {
            $parts[] = 'Icon only. Do not render words, letters, initials, numbers, captions, or text.';
        } elseif ($brandUpper !== '') {
            $parts[] = "Use the brand name exactly as \"{$brandUpper}\". Avoid misspellings and extra text.";
        } else {
            $parts[] = 'Do not render unrelated words, letters, initials, numbers, captions, or text.';
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
    }

    private function buildDefaultImagePrompt(
        string $subject,
        ?string $colorInstruction,
        string $bgInstruction,
        string $imageSize,
        ?string $logoShape = null,
    ): string {
        $subject = trim($subject) !== '' ? rtrim(trim($subject), '.') : 'the requested subject';
        $sizeDirection = match ($imageSize) {
            '16:9' => 'Wide 16:9 composition',
            '9:16' => 'Vertical 9:16 composition',
            default => 'Square 1:1 composition',
        };

        $parts = [
            "Image of {$subject}.",
            "{$sizeDirection}.",
            'Use a clear composition and natural visual treatment without applying a named style preset.',
        ];

        if ($colorInstruction !== null && trim($colorInstruction) !== '') {
            $parts[] = rtrim(trim($colorInstruction), '.') . '.';
        }

        if (trim($bgInstruction) !== '') {
            $parts[] = rtrim(trim($bgInstruction), '.') . '.';
        }

        if (!empty($logoShape) && $logoShape !== 'none') {
            $shape = strtolower($logoShape);
            $parts[] = "Hard shape constraint: the entire image must be fully enclosed inside one clean {$shape} container/badge. The {$shape} is the outer boundary of the artwork, not just a background object. Nothing may extend outside the {$shape}. Keep all subjects, effects, scenery, silhouettes, and color fields clipped inside the {$shape}.";
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
    }

    private function logoThemeInstruction(?string $theme): string
    {
        return match ($theme) {
            'real_estate' => 'Real estate theme: show a modern, premium property or real estate symbol, not an abstract emblem. Use broad property cues such as simplified building silhouettes, window grids, doors, keys, map pins, land parcels, skyline geometry, or clean property-brand shapes. Keep the mark contemporary, crisp, balanced, and spacious with flat vector-friendly geometry. Avoid people, initials, circular monograms, maze-like shapes, ambiguous abstract geometry, and decorative flowing underline strokes unless the user specifically asks for them.',
            'nature' => 'Nature theme: show unmistakable outdoor nature, not a generic eco or utility icon. Use the user requested natural elements literally: trees, forest silhouettes, leaves, branches, hills, landforms, sunrise, sun rays, and any requested season. If trees or a sun are requested, include recognizable tree forms and a visible rising sun or horizon. Do not default to a summer scene unless summer is requested. Avoid light bulbs, water droplets, flames, abstract drops, generic recycle or sustainability marks, unrelated objects, buildings, people, and ambiguous abstract symbols unless the user specifically asks for them.',
            'fantasy' => 'Fantasy theme: show a clear fantasy-adventure visual, not a generic abstract emblem. Use the user requested fantasy elements literally: heroic characters, enchanted creatures, castles, swords, shields, spell effects, glowing runes, moons, mountains, forests, portals, treasure, or quest symbols when relevant. Keep the mark dramatic, readable, and game-ready with strong silhouettes, magical atmosphere, and coherent staging. Avoid corporate office cues, real-estate geometry, generic nature badges, plain monograms, and unrelated modern business symbols unless the user specifically asks for them.',
            'technology' => 'Technology theme: create a clear modern technology identity and represent the user requested technical concept literally. Choose cues that fit the concept: connected nodes and data paths for software, cloud, or networks; circuit traces and chips for hardware, electronics, or AI; shields and locks for cybersecurity; code brackets or terminal forms for developer tools; and robotic, sensor, or signal geometry when relevant. Keep the mark crisp, scalable, and vector-friendly with a few purposeful geometric elements. Do not default every design to the same chip, circuit board, hexagon, globe, atom, or lightning bolt. Avoid over-detailed circuitry, illegible micro-details, generic sci-fi gaming imagery, and unrelated symbols unless the user specifically asks for them.',
            default => '',
        };
    }

    private function natureSeasonInstruction(string $customPrompt): string
    {
        $prompt = strtolower($customPrompt);
        $seasons = [];

        if (preg_match('/\b(winter|snow|snowy|frost|frosty|ice|icy)\b/', $prompt)) {
            $seasons['winter'] = 'winter cues: snow-covered evergreens or bare branches, frost, snow ground, cool white and blue tones';
        }
        if (preg_match('/\b(spring|blossom|blossoms|bloom|blooms|flower|flowers|floral)\b/', $prompt)) {
            $seasons['spring'] = 'spring cues: fresh light greens, new leaves, blossoms, small flowers, soft warm light';
        }
        if (preg_match('/\b(autumn|fall|falling leaves|orange leaves|red leaves|gold leaves)\b/', $prompt)) {
            $seasons['autumn'] = 'autumn cues: orange, gold, and red foliage, falling leaves, warm muted earth tones';
        }
        if (preg_match('/\b(summer|sunny|lush|tropical)\b/', $prompt)) {
            $seasons['summer'] = 'summer cues: lush full green foliage, bright warm sunlight, clear outdoor warmth';
        }

        if ($seasons === []) {
            return '';
        }

        $seasonNames = implode(', ', array_keys($seasons));
        $seasonCues = implode('; ', array_values($seasons));

        if (count($seasons) > 1) {
            return "Season requirement: visibly represent each requested season ({$seasonNames}) with distinct natural cues in the logo mark; {$seasonCues}. Do not collapse the design into a generic summer landscape.";
        }

        return "Season requirement: {$seasonNames}; {$seasonCues}. Make the requested season visually obvious and do not replace it with a generic summer landscape.";
    }

    private function realEstatePromptRequestsRooflineStyle(string $customPrompt): bool
    {
        return preg_match('/\b(roof|roofline|rooftop|swoosh|swooshes|sun|sunburst|sunrise|horizon)\b/i', $customPrompt) === 1;
    }

    private function recraftRequestSize(string $outputFormat, bool $isPro, string $imageSize): string
    {
        if ($outputFormat === 'vector') {
            return '1:1';
        }

        return match (true) {
            $isPro && $imageSize === '16:9' => '2688x1536',
            $isPro && $imageSize === '9:16' => '1536x2688',
            $isPro => '2048x2048',
            $imageSize === '16:9' => '1344x768',
            $imageSize === '9:16' => '768x1344',
            default => '1024x1024',
        };
    }

    private function isUnsupportedRecraftImageSize(string $imageModel, string $outputFormat, bool $isPro, string $imageSize): bool
    {
        return false;
    }

    public function generateLogo(Request $request)
    {
        // Force JSON responses for this endpoint
        $request->headers->set('Accept', 'application/json');
        
        try {
            if (!$request->user()) {
                return response()->json([
                    'error' => 'You must be logged in to generate logos.',
                ], 401);
            }

            $request->validate([
                'domain' => 'nullable|string|max:100',
                'style' => 'required|string|in:' . $this->allowedLogoStylesForValidation(),
                'count' => 'nullable|integer|min:1|max:4',
                'total_count' => 'nullable|integer|min:1|max:4',
                'batch_index' => 'nullable|integer|min:0|max:3',
                'custom_prompt' => 'nullable|string|min:2|max:2000',
                'logo_theme' => 'nullable|string|in:' . $this->allowedLogoThemesForValidation(),
                'pro' => 'nullable|boolean',
                'pro_size' => 'nullable|integer|in:512,1024,1536',
                'icon_only' => 'nullable|boolean',
                'text_only' => 'nullable|boolean',
                'bg_color' => 'nullable|string|max:20',
                'image_model' => 'nullable|string|in:flux,dalle,recraft',
                'output_format' => 'nullable|string|in:raster,vector',
                'image_format' => 'nullable|string|in:png,bmp',
                'recraft_substyle' => 'nullable|string|max:60',
                'logo_shape' => 'nullable|string|in:none,circle,hexagon,triangle,square,pentagon,heart',
                'logo_detail' => 'nullable|string|in:min,medium,max',
                'font_style' => 'nullable|string|in:modern_sans,bold_geometric,elegant_serif,script_signature,tech_mono,minimal_light',
                'color_palette' => 'nullable|array|max:5',
                'color_palette.*' => 'string|max:20',
                'gen_mode' => 'nullable|string|in:logo,image',
                'image_size' => 'nullable|string|in:1:1,16:9,9:16',
            ]);

            $iconOnly = (bool) $request->input('icon_only', false);
            $textOnly = (bool) $request->input('text_only', false);
            $domain = $request->input('domain') ? trim($request->input('domain')) : null;
            $style = $request->input('style');
            $genMode = $request->input('gen_mode', 'logo');
            $genImageSize = $request->input('image_size') ?: '1:1';

            // In image mode there is never logo text, so force icon-only semantics.
            if ($genMode === 'image') {
                $iconOnly = true;
                $textOnly = false;
            }

            // Domain is required for text-only mode and when text is included in logo
            if ($genMode !== 'image' && !$iconOnly && !$domain) {
                return response()->json([
                    'error' => 'Domain name is required when generating logos with text.',
                ], 422);
            }

            $imageCount = $request->input('count', 1);
            $totalCount = $request->input('total_count', $imageCount);
            $batchIndex = $request->input('batch_index', 0);
            $customPrompt = $request->input('custom_prompt');

            // Image mode requires a description to generate from.
            if ($genMode === 'image' && trim((string) $customPrompt) === '') {
                return response()->json([
                    'error' => 'Please describe the image you want to generate.',
                ], 422);
            }

            // ── Trademark / copyright guard ──────────────────────────────────────
            if ($customPrompt) {
                $trademarkCheck = \App\Services\TrademarkFilter::check($customPrompt);
                if (!$trademarkCheck['safe']) {
                    return response()->json(['error' => $trademarkCheck['message']], 422);
                }
            }

            $isPro = (bool) $request->input('pro', false);
            $proSize = (int) $request->input('pro_size', 1024);
            $bgColor = $request->input('bg_color', 'white');
            $imageModel = $request->input('image_model', 'flux');
            $outputFormat = $request->input('output_format', 'raster');
            $imageFormat = $request->input('image_format', 'png');
            $colorPalette = $request->input('color_palette');
            $recraftSubstyle = $request->input('recraft_substyle');
            $logoShape = $request->input('logo_shape', 'none');
            $logoDetail = $request->input('logo_detail', 'max');
            $fontStyle = $request->input('font_style', 'modern_sans');
            $logoTheme = $request->input('logo_theme');

            // DALL-E always produces raster
            if ($imageModel === 'dalle') {
                $outputFormat = 'raster';
            }

            if ($outputFormat === 'vector') {
                $logoDetail = 'max';
                $logoShape = 'none';
            }

            if ($this->isUnsupportedRecraftImageSize($imageModel, $outputFormat, $isPro, $genImageSize)) {
                return response()->json([
                    'error' => 'Ray PRO currently supports Square image size only. Landscape and Portrait are not available for this model.',
                ], 422);
            }

            // Vector outputs must be generated as exactly one mode: icon-only OR text-only.
            if ($outputFormat === 'vector') {
                if ($iconOnly === $textOnly) {
                    return response()->json([
                        'error' => 'Vector generation supports either logo or text, not both.',
                    ], 422);
                }
            }

            // ── Balance check: reject if user can't afford the estimated cost ──
            $user = $request->user();
            $userBalance = (float) $user->credit_balance;

            if ((!is_string($logoShape) || $logoShape === '' || $logoShape === 'none') && $user && Schema::hasTable('logo_generator_settings')) {
                $savedShape = (string) ($this->logoGeneratorSettingsForUser($user)['shape_container'] ?? '');
                if (in_array($savedShape, ['circle', 'square', 'hexagon', 'triangle', 'pentagon'], true)) {
                    $logoShape = $savedShape;
                }
            }

            // Quick pre-check with a generous minimum threshold
            if ($userBalance <= 0) {
                return response()->json([
                    'error' => 'Insufficient balance. Please add credits before generating logos.',
                    'credit_balance' => $userBalance,
                ], 402);
            }

            // Calculate cost estimate for the queued request.
            if ($imageModel === 'recraft') {
                $recraftSize = $this->recraftRequestSize($outputFormat, $isPro, $genImageSize);
                $costEstimate = \App\Services\RecraftPricing::estimateLogoCost(
                    imageCount: $imageCount,
                    size: $recraftSize,
                    isPro: $isPro,
                    type: $outputFormat,
                );
            } elseif ($imageModel === 'dalle') {
                $costEstimate = AiLogoPrice::estimateDalleCost(
                    imageCount: $imageCount,
                    resolution: AiLogoPrice::gptImageResolutionForSize($genImageSize),
                    quality: $isPro ? 'hd' : 'standard',
                    outputFormat: $outputFormat,
                    bgColor: $bgColor,
                );
            } else {
                $costEstimate = AiLogoPrice::estimateCost(
                    imageCount: $imageCount,
                    isPro: $isPro,
                    proSize: $proSize,
                    style: $style,
                    bgColor: $bgColor,
                    outputFormat: $outputFormat,
                    imageModel: $imageModel,
                );
            }
            
            // Calculate total estimated cost for this queued request.
            $costPerImage = $costEstimate['cost_per_image'];
            $estimatedCostForThisRequest = (float) ($costEstimate['estimated_cost_usd'] ?? ($costPerImage * $imageCount));

            // ── Precise balance check against estimated cost (for this request) ──
            if ($estimatedCostForThisRequest > 0 && $userBalance < $estimatedCostForThisRequest) {
                return response()->json([
                    'error' => 'Insufficient balance. This generation costs ~$' . number_format($estimatedCostForThisRequest, 4) . ' but your balance is $' . number_format($userBalance, 4) . '. Please add credits.',
                    'credit_balance' => $userBalance,
                    'estimated_cost' => $estimatedCostForThisRequest,
                ], 402);
            }

            $promptPayload = $this->buildLogoPromptPayload(
                domain: $domain,
                style: $style,
                genMode: $genMode,
                genImageSize: $genImageSize,
                iconOnly: $iconOnly,
                textOnly: $textOnly,
                customPrompt: $customPrompt,
                logoTheme: $logoTheme,
                bgColor: $bgColor,
                imageModel: $imageModel,
                outputFormat: $outputFormat,
                colorPalette: is_array($colorPalette) ? $colorPalette : null,
                logoShape: $logoShape,
                logoDetail: $logoDetail,
                fontStyle: $fontStyle,
            );
            $prompt = $promptPayload['prompt'];

            // Determine model name for logging
            if ($imageModel === 'recraft') {
                $formatTag = $outputFormat === 'vector' ? 'vector' : 'raster';
                $modelName = $outputFormat === 'vector'
                    ? 'recraft-v4-vector'
                    : ($isPro ? "recraft-v4-{$formatTag}" : "recraft-v2-{$formatTag}");
                $imageSize = $outputFormat === 'vector' ? '1:1' : '1024x1024';
                $requestType = $outputFormat === 'vector'
                    ? 'logo_recraft_v4_vector'
                    : ($isPro ? 'logo_recraft_v4_raster' : 'logo_recraft_raster');
            } elseif ($imageModel === 'dalle') {
                $modelName = 'gpt-image-1.5';
                $imageSize = AiLogoPrice::gptImageResolutionForSize($genImageSize);
                $requestType = $isPro ? 'logo_dalle_hd' : 'logo_dalle';
            } else {
                // Flux models
                if ($outputFormat === 'raster' && !$isPro) {
                    // Use nano-banana-2 for raster non-pro flux images
                    $modelName = 'fal-ai/nano-banana-2';
                } else {
                    // Use flux models for pro or vector outputs
                    $modelName = $isPro ? 'fal-ai/flux-2-flex' : 'fal-ai/flux/schnell';
                }
                $imageSize = $isPro ? $proSize . 'x' . $proSize : '512x512';
                $requestType = $isPro ? 'logo_pro' : 'logo_generation';
            }

            $logoRequest = AiLogoRequest::create([
                'user_id' => $user->id,
                'domain' => $domain,
                'style' => $style . ($isPro ? '_pro' : ''),
                'model' => $modelName,
                'output_format' => $outputFormat,
                'seed_number' => null,
                'prompt' => $prompt,
                'original_prompt' => $customPrompt ? trim((string) $customPrompt) : null,
                'status' => 'pending',
            ]);

            // Create price log entry (pending) - log actual count for this request, note batch in preview
            $priceLog = AiLogoPrice::create([
                'user_id' => $user->id,
                'ai_logo_request_id' => $logoRequest->id,
                'session' => session()->getId(),
                'user_email' => $user->email,
                'request_type' => $requestType,
                'model_name' => $modelName,
                'image_count' => $imageCount,
                'image_size' => $imageSize,
                'num_inference_steps' => $imageModel === 'recraft' ? 0 : ($imageModel === 'dalle' ? 0 : ($isPro ? 28 : 8)),
                'guidance_scale' => ($imageModel === 'recraft' || $imageModel === 'dalle') ? 0 : 3.50,
                'cost_per_image' => $costPerImage,
                'estimated_cost_usd' => $estimatedCostForThisRequest,
                'status' => 'pending',
                'prompt_preview' => substr($prompt, 0, 240) . ($imageCount > 1 ? " [{$imageCount} imgs]" : ''),
            ]);

            // ── Dispatch the generation job to the queue ──
            \App\Jobs\GenerateLogoJob::dispatch(
                userId: $this->isAdmin($user) ? 0 : $user->id,
                adminId: $this->isAdmin($user) ? $user->id : null,
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
                    'logo_shape' => $logoShape,
                    'logo_detail' => $logoDetail,
                    'logo_theme' => $logoTheme,
                    'gen_mode' => $genMode,
                    'image_size' => $genImageSize,
                ],
            );

            return response()->json([
                'logo_request_id' => (int) $logoRequest->id,
                'status' => 'queued',
                'message' => 'Logo generation has been queued. Poll /domain-search/logo-status/' . $logoRequest->id . ' for results.',
                'credit_balance' => (float) $user->credit_balance,
                'estimated_cost' => $estimatedCostForThisRequest,
            ]);
        
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Return validation errors as JSON
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            // Catch any other exceptions and return JSON with detailed error
            Log::error('Logo generation request failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);
            
            return response()->json([
                'error' => $this->friendlyErrorMessage($e->getMessage()),
            ], 500);
        }
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
        // Force JSON responses for this endpoint
        $request->headers->set('Accept', 'application/json');
        
        try {
            $currentUser = $request->user();

            // Admins can view any logo request; regular users only see their own
            if (!$currentUser) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            if (!$this->isAdmin($currentUser) && $logoRequest->user_id !== $currentUser->id) {
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
            $resultData = $this->publicLogoResultPayload(is_array($resultData) ? $resultData : []);

            // Refresh user balance
            $currentUser->refresh();

            return response()->json(array_merge([
                'status' => 'completed',
                'logo_request_id' => (int) $logoRequest->id,
                'credit_balance' => (float) $currentUser->credit_balance,
            ], $resultData ?? []));
        }

        // failed or error
        $rawError = $logoRequest->error_message ?? 'Logo generation failed.';

        return response()->json([
            'status' => $status,
            'error' => $this->friendlyErrorMessage($rawError),
            'credit_balance' => (float) $currentUser->credit_balance,
        ]);
        
        } catch (\Exception $e) {
            Log::error('Logo status check failed', [
                'error' => $e->getMessage(),
                'logo_request_id' => $logoRequest->id ?? null,
            ]);
            return response()->json([
                'error' => 'An unexpected error occurred.',
                'status' => 'error',
            ], 500);
        }
    }

    private function isAdmin(?object $user): bool
    {
        return $user instanceof Admin;
    }

    private function debitUserBalance(
        \Illuminate\Foundation\Auth\User|Admin $user,
        float $amount,
        string $service,
        string $modelName,
        string $description,
        ?array $metadata = null,
    ): void {
        if ($user instanceof Admin) {
            $user->debitBalance($amount);
        } else {
            CreditTransaction::debit(
                userId: $user->id,
                amount: $amount,
                service: $service,
                modelName: $modelName,
                description: $description,
                metadata: $metadata,
            );
        }
    }

    /**
     * Convert raw API error messages into user-friendly text.
     */
    private function friendlyErrorMessage(string $raw): string
    {
        $normalized = strtolower($raw);

        if (
            str_contains($normalized, 'not_enough_credits') ||
            str_contains($normalized, 'user is locked') ||
            str_contains($normalized, 'exhausted balance')
        ) {
            return 'Model currently unavailable, please try a different model.';
        }

        if (
            str_contains($normalized, 'http request returned status code') ||
            str_contains($normalized, 'invalid_request_parameter') ||
            str_contains($normalized, 'invalid response') ||
            str_contains($normalized, 'invalid json') ||
            str_contains($normalized, 'api returned') ||
            str_contains($normalized, 'api key') ||
            str_contains($normalized, 'recraft') ||
            str_contains($normalized, 'dall-e') ||
            str_contains($normalized, 'gpt-image') ||
            str_contains($normalized, 'fal-ai') ||
            str_contains($normalized, 'openai')
        ) {
            return 'Image generation failed. Please adjust your settings and try again.';
        }

        if (str_contains($raw, 'content filters') || str_contains($raw, 'content_policy') || str_contains($raw, 'safety system')) {
            return 'Your prompt was flagged by the AI safety filter. Please rephrase your description and try again — avoid violent, sexual, or trademarked content.';
        }
        if (str_contains($raw, 'rate limit') || str_contains($raw, 'Rate limit')) {
            return 'The AI service is temporarily busy. Please wait a moment and try again.';
        }
        if (str_contains($raw, 'Billing hard limit') || str_contains($raw, 'billing')) {
            return 'Image generation is temporarily unavailable. Please use another model.';
        }
        if (str_contains($raw, 'quota')) {
            return 'The AI service quota has been reached. Please try again later or switch to a different model.';
        }
        if (str_contains($raw, 'invalid_api_key') || str_contains($raw, 'Incorrect API key')) {
            return 'There is a configuration issue with the AI service. Please contact support.';
        }
        return 'Image generation failed. Please try again.';
    }

    private function publicEstimatePayload(array $estimate): array
    {
        unset(
            $estimate['model'],
            $estimate['source'],
            $estimate['prices'],
            $estimate['units_per_image']
        );

        return $estimate;
    }

    private function publicLogoResultPayload(array $resultData): array
    {
        unset(
            $resultData['image_model'],
            $resultData['model'],
            $resultData['provider'],
            $resultData['api']
        );

        if (isset($resultData['images']) && is_array($resultData['images'])) {
            $resultData['images'] = array_values(array_filter(array_map(function ($image) {
                if (!is_array($image)) {
                    return is_string($image) && str_starts_with($image, '/storage/')
                        ? ['url' => $image]
                        : null;
                }

                $publicUrl = $image['stored_url'] ?? $image['url'] ?? null;
                if (!is_string($publicUrl) || !str_starts_with($publicUrl, '/storage/')) {
                    return null;
                }

                return array_filter([
                    'url' => $publicUrl,
                    'stored_url' => $publicUrl,
                    'generation_id' => $image['generation_id'] ?? null,
                    'provider_image_id' => $image['provider_image_id'] ?? $image['image_id'] ?? null,
                    'seed' => $image['seed'] ?? null,
                ], fn ($value) => $value !== null);
            }, $resultData['images'])));
        }

        return $resultData;
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

            $contentType = strtolower((string) ($response->header('Content-Type') ?? ''));
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
            'output_format' => 'raster',
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
                $falErrorRaw = $response->json('detail') ?? $response->json('message') ?? $response->body() ?? 'Unknown error';
                $falError = $this->friendlyErrorMessage($falErrorRaw);
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
            $friendlyError = $this->friendlyErrorMessage($e->getMessage());

            $logoRequest->update([
                'status' => 'error',
                'error_message' => $friendlyError,
                'response_time_ms' => $elapsedMs,
            ]);

            $priceLog->update([
                'status' => 'error',
                'response_time_ms' => $elapsedMs,
            ]);

            return response()->json([
                'error' => 'PRO generation failed: ' . $friendlyError,
            ], 500);
        }
    }

    /**
     * Upscale a generated raster image with Topaz.
     */
    public function upscaleLogo(Request $request)
    {
        if (!$request->user()) {
            return response()->json([
                'error' => 'You must be logged in to upscale images.',
            ], 401);
        }

        $request->validate([
            'image_url' => 'required|string',
            'upscale_factor' => 'nullable|integer|in:2',
            'logo_request_id' => 'nullable|integer',
            'image_index' => 'nullable|integer|min:0',
        ]);

        $user = $request->user();
        $imageUrl = $request->input('image_url');
        $upscaleFactor = (int) $request->input('upscale_factor', 2);
        $logoRequest = null;
        $imageIndex = $request->filled('image_index') ? (int) $request->input('image_index') : null;

        if ($request->filled('logo_request_id')) {
            $logoRequest = AiLogoRequest::find((int) $request->input('logo_request_id'));

            if (!$logoRequest) {
                return response()->json([
                    'error' => 'Logo request not found.',
                ], 404);
            }

            if (!$this->isAdmin($user) && (int) $logoRequest->user_id !== (int) $user->id) {
                return response()->json([
                    'error' => 'You are not allowed to upscale this image.',
                ], 403);
            }

            if ($imageIndex !== null && !array_key_exists($imageIndex, array_values((array) $logoRequest->image_urls))) {
                return response()->json([
                    'error' => 'Image index not found for this logo request.',
                ], 422);
            }

            if ($imageIndex !== null) {
                $imageUrls = array_values((array) $logoRequest->image_urls);
                if (isset($imageUrls[$imageIndex]) && is_string($imageUrls[$imageIndex]) && $imageUrls[$imageIndex] !== '') {
                    $imageUrl = $imageUrls[$imageIndex];
                }
            }
        }

        $estimate = AiLogoPrice::estimateUpscaleCost(upscaleFactor: $upscaleFactor);
        $upscaleCost = (float) $estimate['estimated_cost_usd'];

        if ((float) $user->credit_balance < $upscaleCost) {
            return response()->json([
                'error' => 'Insufficient balance. Upscaling costs ~$' . number_format($upscaleCost, 2) . '. Please add credits.',
                'credit_balance' => (float) $user->credit_balance,
                'estimated_cost' => $upscaleCost,
            ], 402);
        }

        $falKey = config('services.fal.key');

        $startTime = microtime(true);

        try {
            $falImageInput = $this->prepareFalImageInput($imageUrl);
            $topazUrl = 'https://fal.run/fal-ai/topaz/upscale/image';
            $upscaleResponse = $this->httpWithResolvedDns($topazUrl, [
                'Authorization' => 'Key ' . $falKey,
                'Content-Type' => 'application/json',
            ])->retry(3, 2000, function (\Exception $e, \Illuminate\Http\Client\PendingRequest $request) {
                return $e instanceof \Illuminate\Http\Client\ConnectionException
                    || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->serverError());
            })->timeout(300)->post($topazUrl, [
                'image_url' => $falImageInput,
                'upscale_factor' => $upscaleFactor,
                'model' => 'Standard V2',
                'output_format' => 'png',
                'subject_detection' => 'All',
                'face_enhancement' => false,
                'sharpen' => 0.2,
                'denoise' => 0.1,
                'fix_compression' => 0.2,
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
            $upscaledWidth = $upscaleData['image']['width'] ?? null;
            $upscaledHeight = $upscaleData['image']['height'] ?? null;

            if (!$upscaledUrl) {
                return response()->json([
                    'error' => 'Upscaler returned no image.',
                ], 500);
            }

            $storedUpscale = $this->storeUpscaledImage((int) $user->id, $upscaledUrl);
            $servedUpscaledUrl = $storedUpscale['url'] ?? $upscaledUrl;

            if ($logoRequest && $imageIndex !== null) {
                $imageUrls = array_values((array) $logoRequest->image_urls);
                $imageUrls[$imageIndex] = $servedUpscaledUrl;
                $logoRequest->update([
                    'image_urls' => $imageUrls,
                    'storage_type' => $storedUpscale ? 'path' : ($logoRequest->storage_type ?: 'url'),
                ]);
            }

            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

            AiLogoPrice::create([
                'user_id' => $user->id,
                'session' => session()->getId(),
                'user_email' => $user->email,
                'request_type' => 'image_upscale',
                'model_name' => 'fal-ai/topaz/upscale/image',
                'image_count' => 1,
                'image_size' => $upscaleFactor . 'x upscale',
                'num_inference_steps' => 0,
                'guidance_scale' => 0,
                'cost_per_image' => $upscaleCost,
                'estimated_cost_usd' => $upscaleCost,
                'actual_cost_usd' => $upscaleCost,
                'status' => 'completed',
                'prompt_preview' => 'Upscale image via Topaz ' . $upscaleFactor . 'x',
                'response_time_ms' => $elapsedMs,
            ]);

            $this->debitUserBalance(
                $user,
                $upscaleCost,
                'image_upscale',
                'fal-ai/topaz/upscale/image',
                'Image upscale ' . $upscaleFactor . 'x',
                [
                    'original_url' => $imageUrl,
                    'upscaled_url' => $servedUpscaledUrl,
                    'provider_url' => $upscaledUrl,
                    'upscale_factor' => $upscaleFactor,
                    'width' => $upscaledWidth,
                    'height' => $upscaledHeight,
                ],
            );

            $user->refresh();

            return response()->json([
                'original_url' => $imageUrl,
                'upscaled_url' => $servedUpscaledUrl,
                'provider_url' => $upscaledUrl,
                'logo_request_id' => $logoRequest ? (int) $logoRequest->id : null,
                'image_index' => $imageIndex,
                'width' => $upscaledWidth,
                'height' => $upscaledHeight,
                'cost' => $upscaleCost,
                'credit_balance' => (float) $user->credit_balance,
                'processing_time_ms' => $elapsedMs,
            ]);
        } catch (\Exception $e) {
            \Log::error('Image upscale error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Upscale failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function prepareFalImageInput(string $imageUrl): string
    {
        $path = parse_url($imageUrl, PHP_URL_PATH) ?: $imageUrl;

        if (str_starts_with($path, '/storage/')) {
            $localPath = storage_path('app/public/' . substr($path, 9));
            if (!file_exists($localPath)) {
                throw new \RuntimeException('Source image not found on disk.');
            }

            $mime = mime_content_type($localPath) ?: 'image/png';
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($localPath));
        }

        return $imageUrl;
    }

    private function storeUpscaledImage(int $userId, string $imageUrl): ?array
    {
        try {
            $response = $this->httpWithResolvedDns($imageUrl, [])->timeout(60)->get($imageUrl);
            if (!$response->successful()) {
                \Log::warning('Failed to download upscaled image', [
                    'status' => $response->status(),
                    'image_url' => $imageUrl,
                ]);
                return null;
            }

            $contentType = strtolower((string) ($response->header('Content-Type') ?? ''));
            $extension = match (true) {
                str_contains($contentType, 'jpeg'), str_contains($contentType, 'jpg') => 'jpg',
                str_contains($contentType, 'webp') => 'webp',
                default => 'png',
            };

            $filename = sprintf('upscaled-%s-%s.%s', now()->format('YmdHis'), Str::random(6), $extension);
            $relativePath = sprintf('logos/%d/upscaled/%s', $userId, $filename);

            Storage::disk('public')->put($relativePath, $response->body());

            return [
                'path' => $relativePath,
                'url' => '/storage/' . $relativePath,
            ];
        } catch (\Throwable $e) {
            \Log::warning('Exception while storing upscaled image', [
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
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
            $this->debitUserBalance(
                $request->user(),
                $cost,
                'logo_bg_removal',
                'recraft/removeBackground',
                'Logo background removal',
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

    public function saveProcessedSvg(Request $request)
    {
        $request->validate([
            'svg' => 'required|string',
        ]);

        try {
            $svgContent = $request->input('svg');
            
            // Generate a unique filename
            $filename = 'processed-' . uniqid() . '.svg';
            $path = 'logos/' . $filename;
            
            // Store in public disk
            \Storage::disk('public')->put($path, $svgContent);
            
            // Return the public URL
            $publicStorageUrl = rtrim((string) config('filesystems.disks.public.url', '/storage'), '/');
            $url = $publicStorageUrl . '/' . ltrim($path, '/');
            
            return response()->json([
                'success' => true,
                'url' => $url,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error saving processed SVG', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => 'Failed to save processed SVG',
            ], 500);
        }
    }
}
