<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Overview extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Overview';

    protected static ?int $navigationSort = -2;

    protected static string $view = 'filament.pages.overview';

    public function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\CreditBalanceWidget::class,
            \App\Filament\Widgets\UsageChartWidget::class,
            \App\Filament\Widgets\RecentTransactionsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int | array
    {
        return 1;
    }
}
