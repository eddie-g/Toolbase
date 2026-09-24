<?php

namespace App\UserPortal\Pages;

use App\Models\AiLogoPrice;
use App\Models\AiLogoRequest;
use App\Services\GeneratedImageTrash;
use App\Services\LogoShowcase;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\WithPagination;

/**
 * The account's generated images, one item per image (a batch of four is four
 * items): open in the studio, rename, download, upscale, and delete into a
 * Trash that empties itself after AiLogoRequest::TRASH_DAYS days. Per-image
 * state lives in ai_logo_requests.image_meta.
 */
class ImageGenerator extends Page
{
    use WithPagination;

    protected static ?string $title = 'Images';

    protected static ?string $navigationLabel = 'Images';

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'user-portal.pages.image-generator';

    private const PER_PAGE = 24;

    public string $viewMode = 'grid';
    public string $formatFilter = 'all';
    public ?string $editingImageKey = null;
    public string $editingName = '';

    /** allItems() for this request; cleared by every action that changes an image. */
    private ?Collection $items = null;

    public function setViewMode(string $mode): void
    {
        $this->viewMode = in_array($mode, ['grid', 'table'], true) ? $mode : 'grid';
    }

    public function setFormatFilter(string $filter): void
    {
        $this->formatFilter = in_array($filter, ['all', 'raster', 'vector', 'trash'], true) ? $filter : 'all';
        $this->cancelRename();
        $this->resetPage();
    }

    public function startRename(int $id, int $index): void
    {
        $record = $this->ownedRequest($id);
        if (!$record) {
            return;
        }

        $this->editingImageKey = $id . '-' . $index;
        $this->editingName = (string) ($record->imageMeta($index)['name'] ?? $record->domain ?? '');
    }

    public function cancelRename(): void
    {
        $this->editingImageKey = null;
        $this->editingName = '';
    }

    /** Names one image of a batch; the batch's own domain (the logo text) is left alone. */
    public function saveRename(int $id, int $index): void
    {
        if ($this->editingImageKey !== $id . '-' . $index) {
            return;
        }

        $this->validate([
            'editingName' => ['required', 'string', 'max:255'],
        ]);

        $this->ownedRequest($id)?->updateImageMeta($index, ['name' => trim($this->editingName)]);
        $this->items = null;

        $this->cancelRename();
    }

    public function trashImage(int $id, int $index): void
    {
        $record = $this->ownedRequest($id);
        if (!$record || $record->isImageHidden($index)) {
            return;
        }

        app(GeneratedImageTrash::class)->trash($record, $index);
        $this->items = null;

        Notification::make()
            ->title('Moved to Trash')
            ->body('It will be deleted permanently in ' . AiLogoRequest::TRASH_DAYS . ' days.')
            ->success()
            ->actions([
                NotificationAction::make('undo')
                    ->label('Undo')
                    ->button()
                    ->dispatch('restore-image', ['id' => $id, 'index' => $index])
                    ->close(),
            ])
            ->send();
    }

    #[On('restore-image')]
    public function restoreImage(int $id, int $index): void
    {
        $record = $this->ownedRequest($id);
        if (!$record) {
            return;
        }

        app(GeneratedImageTrash::class)->restore($record, $index);
        $this->items = null;

        Notification::make()->title('Image restored')->success()->send();
    }

    public function purgeImage(int $id, int $index): void
    {
        $record = $this->ownedRequest($id);
        if (!$record || $record->isImagePurged($index)) {
            return;
        }

        app(GeneratedImageTrash::class)->purge($record, $index);
        $this->items = null;

        Notification::make()->title('Image deleted permanently')->success()->send();
    }

    public function emptyTrash(): void
    {
        $trash = app(GeneratedImageTrash::class);
        $count = 0;

        foreach ($this->allItems()->where('trashed', true) as $item) {
            $record = $this->ownedRequest($item['id']);
            if ($record) {
                $trash->purge($record, $item['index']);
                $count++;
            }
        }

        $this->items = null;
        $this->resetPage();

        Notification::make()
            ->title($count === 1 ? '1 image deleted permanently' : "{$count} images deleted permanently")
            ->success()
            ->send();
    }

    public static function modelLabel(?string $model): string
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

    /** What one upscale costs the user, shown on the button before they click. */
    public function getUpscaleCostProperty(): float
    {
        return (float) AiLogoPrice::estimateUpscaleCost()['estimated_cost_usd'];
    }

    public function getTrashCountProperty(): int
    {
        return $this->allItems()->where('trashed', true)->count();
    }

    /** The current tab's images, one page of them, with the studio preset of each. */
    public function getImagesProperty(): LengthAwarePaginator
    {
        $items = $this->allItems()->filter(fn (array $item) => match ($this->formatFilter) {
            'trash' => $item['trashed'],
            'raster' => !$item['trashed'] && !$item['vector'],
            'vector' => !$item['trashed'] && $item['vector'],
            default => !$item['trashed'],
        })->values();

        $page = max(1, $this->getPage());
        $slice = $items->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();

        // Presets need result_data, which can be large: only for this page.
        $full = AiLogoRequest::query()
            ->whereIn('id', $slice->pluck('id')->unique())
            ->get(['id', 'user_id', 'domain', 'style', 'model', 'output_format', 'seed_number', 'original_prompt', 'width', 'height', 'response_time_ms', 'result_data', 'image_urls', 'created_at'])
            ->keyBy('id');
        $showcase = app(LogoShowcase::class);
        $slice = $slice->map(function (array $item) use ($full, $showcase) {
            $logo = $full->get($item['id']);
            $item['preset'] = $logo ? $showcase->item($logo, $item['index'], $item['url']) : null;

            return $item;
        });

        return new LengthAwarePaginator($slice, $items->count(), self::PER_PAGE, $page);
    }

    /**
     * Every image of the account's completed generations, newest first.
     * Purged images and base64 placeholders the nightly redaction left are
     * not images any more and are skipped.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function allItems(): Collection
    {
        return $this->items ??= (function () {
            $costPerImage = AiLogoPrice::query()
                ->select('cost_per_image')
                ->whereColumn('ai_logo_request_id', 'ai_logo_requests.id')
                ->orderByDesc('id')
                ->limit(1);

            $tz = auth()->user()?->displayTimezone() ?? config('app.timezone');

            return AiLogoRequest::query()
                ->select(['id', 'domain', 'model', 'output_format', 'original_prompt', 'image_urls', 'image_meta', 'created_at'])
                ->selectSub($costPerImage, 'cost_per_image')
                ->where('user_id', auth()->id())
                ->where('status', 'completed')
                ->whereNotNull('image_urls')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->flatMap(function (AiLogoRequest $request) use ($tz) {
                    $items = [];
                    foreach (array_values((array) $request->image_urls) as $index => $url) {
                        if (!is_string($url) || $url === '' || $url === '[base64-omitted]' || $request->isImagePurged($index)) {
                            continue;
                        }

                        $meta = $request->imageMeta($index);
                        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
                        $trashedAt = !empty($meta['trashed_at']) ? Carbon::parse($meta['trashed_at']) : null;

                        // Previews are cached for a year; the file behind an index
                        // changes when it is upscaled, so the url carries its version.
                        $version = ['v' => substr(md5($url), 0, 8)];

                        $items[] = [
                            'id' => (int) $request->id,
                            'index' => $index,
                            'key' => $request->id . '-' . $index,
                            'url' => $url,
                            'preview_url' => route('generatedImages.preview', ['logoRequest' => $request->id, 'index' => $index, ...$version]),
                            'original_url' => route('generatedImages.original', ['logoRequest' => $request->id, 'index' => $index, ...$version]),
                            'name' => $meta['name'] ?? $request->domain,
                            'prompt' => $request->original_prompt,
                            'generator' => self::modelLabel($request->model),
                            'cost' => $request->cost_per_image !== null ? (float) $request->cost_per_image : null,
                            'vector' => $request->output_format === 'vector' || str_ends_with($path, '.svg'),
                            'upscaled' => is_array($meta['upscaled'] ?? null) ? $meta['upscaled'] : null,
                            'trashed' => $trashedAt !== null,
                            'purge_on' => $trashedAt?->copy()->addDays(AiLogoRequest::TRASH_DAYS)->timezone($tz),
                            'created_at' => $request->created_at?->copy()->timezone($tz),
                        ];
                    }

                    return $items;
                })
                ->values();
        })();
    }

    private function ownedRequest(int $id): ?AiLogoRequest
    {
        return AiLogoRequest::query()
            ->where('user_id', auth()->id())
            ->whereKey($id)
            ->first(['id', 'user_id', 'domain', 'image_urls', 'image_meta', 'is_showcase', 'showcase_image_indexes']);
    }
}
