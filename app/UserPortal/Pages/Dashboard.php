<?php

namespace App\UserPortal\Pages;

use App\UserPortal\Support\RecentActivity;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Livewire\Attributes\Url;

/**
 * The portal home: balance and 30-day numbers, shortcuts to each tool, and one
 * feed of recent actions across PDFs, images, domains and credits. Styled
 * with the portal's nk-* classes instead of Filament widgets.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Overview';

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = -2;

    protected static string $view = 'user-portal.pages.overview';

    private const PAGE_SIZE = 20;

    #[Url(as: 'show', except: 'all')]
    public string $kind = 'all';

    public int $limit = self::PAGE_SIZE;

    public function mount(): void
    {
        if (!in_array($this->kind, ['all', ...RecentActivity::KINDS], true)) {
            $this->kind = 'all';
        }

        if (request()->boolean('verified')) {
            Notification::make()
                ->title('Email successfully verified')
                ->success()
                ->send();
        }
    }

    public function setKind(string $kind): void
    {
        $this->kind = in_array($kind, ['all', ...RecentActivity::KINDS], true) ? $kind : 'all';
        $this->limit = self::PAGE_SIZE;
    }

    public function showMore(): void
    {
        $this->limit = min($this->limit + self::PAGE_SIZE, 200);
    }

    public function getWidgets(): array
    {
        return [];
    }

    protected function getViewData(): array
    {
        $feed = new RecentActivity(auth()->user());
        // One extra row tells the view whether "Show more" has anything left.
        $items = $feed->items($this->kind, $this->limit + 1);

        return [
            'stats' => $feed->stats(),
            'hasMore' => $items->count() > $this->limit && $this->limit < 200,
            'days' => $items->take($this->limit)->groupBy(fn (array $item) => $item['at']->toDateString()),
            'today' => now(auth()->user()->displayTimezone())->startOfDay(),
        ];
    }
}
