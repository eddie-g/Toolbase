<?php

namespace App\Filament\Pages;

use App\Models\AiLogoPrice;
use App\Models\AiLogoRequest;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Livewire\WithPagination;

class Logos extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-paint-brush';

    protected static ?string $navigationLabel = 'Logos';

    protected static ?string $title = 'Logos';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.logos';

    public string $search = '';

    public string $filterFavourites = '';
    public string $formatFilter = 'all';
    public string $viewMode = 'grid';
    public ?int $editingRequestId = null;
    public ?string $editingImageKey = null;
    public string $editingName = '';

    protected $queryString = [
        'search' => ['except' => ''],
        'filterFavourites' => ['except' => ''],
        'formatFilter' => ['except' => 'all'],
        'viewMode' => ['except' => 'grid'],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterFavourites(): void
    {
        $this->resetPage();
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = in_array($mode, ['grid', 'table'], true) ? $mode : 'grid';
    }

    public function setFormatFilter(string $filter): void
    {
        $this->formatFilter = in_array($filter, ['all', 'raster', 'vector'], true) ? $filter : 'all';
        $this->resetPage();
    }

    public function startRename(int $id, ?string $imageKey = null): void
    {
        $record = AiLogoRequest::query()
            ->whereKey($id)
            ->first();

        if (!$record) {
            return;
        }

        $this->editingRequestId = (int) $record->id;
        $this->editingImageKey = $imageKey ?: 'request-' . $record->id;
        $this->editingName = (string) ($record->domain ?? '');
    }

    public function cancelRename(): void
    {
        $this->editingRequestId = null;
        $this->editingImageKey = null;
        $this->editingName = '';
    }

    public function saveRename(int $id): void
    {
        if ($this->editingRequestId !== $id) {
            return;
        }

        $this->validate([
            'editingName' => ['required', 'string', 'max:255'],
        ]);

        AiLogoRequest::query()
            ->whereKey($id)
            ->update(['domain' => trim($this->editingName)]);

        $this->cancelRename();
    }

    public function toggleFavourite(int $id): void
    {
        $logo = AiLogoRequest::find($id);
        if (!$logo) return;

        $logo->update(['is_favourited' => !$logo->is_favourited]);

        Notification::make()
            ->title($logo->is_favourited ? 'Added to favourites' : 'Removed from favourites')
            ->success()
            ->send();
    }

    public function toggleShowcase(int $id, int $imageIndex): void
    {
        $logo = AiLogoRequest::find($id);
        if (!$logo) return;

        $urls = array_values((array) $logo->image_urls);
        if (!array_key_exists($imageIndex, $urls)) {
            Notification::make()
                ->title('Logo image not found')
                ->danger()
                ->send();

            return;
        }

        $storedIndexes = $logo->showcase_image_indexes;
        if ((bool) $logo->is_showcase && (!is_array($storedIndexes) || $storedIndexes === [])) {
            $storedIndexes = array_keys($urls);
        }

        $indexes = collect($storedIndexes ?: [])
            ->map(fn ($index) => (int) $index)
            ->filter(fn (int $index) => array_key_exists($index, $urls))
            ->unique()
            ->values();

        $isCurrentlyShowcased = $indexes->contains($imageIndex);
        $indexes = $isCurrentlyShowcased
            ? $indexes->reject(fn (int $index) => $index === $imageIndex)->values()
            : $indexes->push($imageIndex)->unique()->values();

        $logo->update([
            'is_showcase' => $indexes->isNotEmpty(),
            'showcase_image_indexes' => $indexes->isNotEmpty() ? $indexes->all() : null,
        ]);

        Notification::make()
            ->title($isCurrentlyShowcased ? 'Removed from showcase' : 'Showcase this logo enabled')
            ->success()
            ->send();
    }

    public function getRequestsProperty(): LengthAwarePaginator
    {
        $costSubquery = AiLogoPrice::query()
            ->selectRaw('COALESCE(actual_cost_usd, estimated_cost_usd)')
            ->whereColumn('ai_logo_request_id', 'ai_logo_requests.id')
            ->orderByDesc('id')
            ->limit(1);

        $query = AiLogoRequest::query()
            ->select('ai_logo_requests.*')
            ->selectSub($costSubquery, 'latest_cost_usd')
            ->where('status', 'completed')
            ->whereNotNull('image_urls')
            ->with('user');

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('prompt', 'like', '%' . $this->search . '%')
                  ->orWhere('original_prompt', 'like', '%' . $this->search . '%')
                  ->orWhere('domain', 'like', '%' . $this->search . '%')
                  ->orWhere('style', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->filterFavourites === 'favourites') {
            $query->where('is_favourited', true);
        }

        if ($this->filterFavourites === 'showcase') {
            $query->where('is_showcase', true);
        }

        if ($this->formatFilter !== 'all') {
            $query->where('output_format', $this->formatFilter);
        }

        return $query->orderByDesc('created_at')->paginate(12);
    }

    public function getViewData(): array
    {
        // Flatten each logo's image_urls into individual image items
        $items = collect();
        foreach ($this->requests as $logo) {
            $urls = is_array($logo->image_urls) ? $logo->image_urls : [];
            $showcaseIndexes = collect($logo->showcase_image_indexes ?: [])
                ->map(fn ($index) => (int) $index)
                ->all();

            foreach ($urls as $idx => $url) {
                if (!is_string($url) || $url === '' || $url === '[base64-omitted]') continue;

                // Normalize absolute URLs to relative
                $parsed = parse_url($url);
                if (isset($parsed['host']) && isset($parsed['path'])) {
                    $url = $parsed['path'];
                }

                // Determine file size
                $sizeBytes = null;
                if (str_starts_with($url, '/storage/')) {
                    $relativePath = substr($url, strlen('/storage/'));
                    $fullPath = Storage::disk('public')->path($relativePath);
                    if (file_exists($fullPath)) {
                        $sizeBytes = filesize($fullPath);
                    }
                }

                $previewUrl = route('generatedImages.preview', ['logoRequest' => $logo->id, 'index' => $idx]);
                $originalUrl = route('generatedImages.original', ['logoRequest' => $logo->id, 'index' => $idx]);
                $resultData = is_string($logo->result_data)
                    ? (json_decode($logo->result_data, true) ?: [])
                    : (is_array($logo->result_data) ? $logo->result_data : []);
                $imageData = $resultData['images'][$idx] ?? [];
                $imageSeed = is_array($imageData) ? ($imageData['seed'] ?? null) : null;

                $items->push([
                    'logo_id' => $logo->id,
                    'image_index' => $idx,
                    'url' => $url,
                    'preview_url' => $previewUrl,
                    'original_url' => $originalUrl,
                    'prompt' => $logo->original_prompt ?: $logo->prompt,
                    'domain' => $logo->domain,
                    'style' => $logo->style,
                    'generator' => $this->modelLabel($logo->model),
                    'model' => $logo->model,
                    'output_format' => $logo->output_format,
                    'cost' => $logo->latest_cost_usd,
                    'seed_number' => $imageSeed ?? $logo->seed_number,
                    'bg_color' => $resultData['bg_color'] ?? null,
                    'image_model' => $resultData['image_model'] ?? null,
                    'style_raw' => $resultData['style'] ?? null,
                    'icon_only' => $resultData['icon_only'] ?? false,
                    'logo_shape' => $resultData['logo_shape'] ?? null,
                    'logo_detail' => $resultData['logo_detail'] ?? null,
                    'is_vector' => $logo->output_format === 'vector' || str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.svg'),
                    'is_favourited' => (bool) $logo->is_favourited,
                    'is_showcase' => in_array((int) $idx, $showcaseIndexes, true) || ((bool) $logo->is_showcase && $showcaseIndexes === []),
                    'size_bytes' => $sizeBytes,
                    'size_human' => $sizeBytes ? $this->humanFileSize($sizeBytes) : null,
                    'created_at' => $logo->created_at,
                    'user_name' => $logo->user?->name ?? 'Unknown',
                    'user_email' => $logo->user?->email,
                ]);
            }
        }

        return [
            'items' => $items,
            'logos' => $this->requests, // for pagination links
        ];
    }

    public function modelLabel(?string $model): string
    {
        $model = strtolower((string) $model);

        if (str_contains($model, 'recraft')) {
            return 'Ray';
        }
        if (str_contains($model, 'flux') || str_contains($model, 'nano-banana')) {
            return 'Luna';
        }
        if (str_contains($model, 'gpt-image') || str_contains($model, 'dall-e')) {
            return 'Cosmo';
        }

        return 'Cosmo';
    }

    private function humanFileSize(?int $bytes): string
    {
        if ($bytes === null || $bytes === 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
