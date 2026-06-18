<?php

namespace App\UserPortal\Pages;

use App\Models\AiLogoRequest;
use App\Models\AiLogoPrice;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

class ImageGenerator extends Page
{
    use WithPagination;

    protected static ?string $title = 'Images';

    protected static ?string $navigationLabel = 'Images';

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'user-portal.pages.image-generator';

    public string $viewMode = 'grid';
    public string $formatFilter = 'all';
    public ?int $editingRequestId = null;
    public ?string $editingImageKey = null;
    public string $editingName = '';

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
            ->where('user_id', auth()->id())
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
            ->where('user_id', auth()->id())
            ->whereKey($id)
            ->update(['domain' => trim($this->editingName)]);

        $this->cancelRename();
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

    public function getRequestsProperty(): LengthAwarePaginator
    {
        $costSubquery = AiLogoPrice::query()
            ->selectRaw('COALESCE(actual_cost_usd, estimated_cost_usd)')
            ->whereColumn('ai_logo_request_id', 'ai_logo_requests.id')
            ->orderByDesc('id')
            ->limit(1);

        return AiLogoRequest::query()
            ->select('ai_logo_requests.*')
            ->selectSub($costSubquery, 'latest_cost_usd')
            ->where('user_id', auth()->id())
            ->where('status', 'completed')
            ->whereNotNull('image_urls')
            ->when(
                $this->formatFilter !== 'all',
                fn ($q) => $q->where('output_format', $this->formatFilter)
            )
            ->orderByDesc('created_at')
            ->paginate(12);
    }
}
