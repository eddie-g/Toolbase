<?php

namespace App\Filament\Pages;

use App\Models\AiLogoRequest;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
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

    protected $queryString = [
        'search' => ['except' => ''],
        'filterFavourites' => ['except' => ''],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterFavourites(): void
    {
        $this->resetPage();
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

    public function getViewData(): array
    {
        $query = AiLogoRequest::query()
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

        $logos = $query->orderByDesc('created_at')->paginate(24);

        // Flatten each logo's image_urls into individual image items
        $items = collect();
        foreach ($logos as $logo) {
            $urls = is_array($logo->image_urls) ? $logo->image_urls : [];
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

                $items->push([
                    'logo_id' => $logo->id,
                    'image_index' => $idx,
                    'url' => $url,
                    'prompt' => $logo->original_prompt ?: $logo->prompt,
                    'domain' => $logo->domain,
                    'style' => $logo->style,
                    'is_favourited' => (bool) $logo->is_favourited,
                    'size_bytes' => $sizeBytes,
                    'size_human' => $sizeBytes ? $this->humanFileSize($sizeBytes) : null,
                    'created_at' => $logo->created_at,
                    'user_name' => $logo->user?->name ?? 'Unknown',
                ]);
            }
        }

        return [
            'items' => $items,
            'logos' => $logos, // for pagination links
        ];
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
