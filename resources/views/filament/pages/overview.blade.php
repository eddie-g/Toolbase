<x-filament-panels::page>
    @livewire(\App\Filament\Widgets\CreditBalanceWidget::class)

    <div class="mt-6">
        @livewire(\App\Filament\Widgets\UsageChartWidget::class)
    </div>

    <div class="mt-6">
        @livewire(\App\Filament\Widgets\RecentTransactionsWidget::class)
    </div>
</x-filament-panels::page>
