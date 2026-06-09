<?php

namespace App\UserPortal\Pages;

use App\UserPortal\Widgets\UserCreditBalanceWidget;
use App\UserPortal\Widgets\UserRecentTransactionsWidget;
use App\UserPortal\Widgets\UserUsageChartWidget;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Overview';

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = -2;

    public function mount(): void
    {
        if (request()->boolean('verified')) {
            Notification::make()
                ->title('Email successfully verified')
                ->success()
                ->send();
        }
    }

    protected function getHeaderWidgets(): array
    {
        return [
            UserCreditBalanceWidget::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            UserUsageChartWidget::class,
            UserRecentTransactionsWidget::class,
        ];
    }
}
