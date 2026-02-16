<?php

namespace App\Filament\Widgets;

use App\Models\CreditTransaction;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class CreditBalanceWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected function getStats(): array
    {
        $user = auth()->user();
        $currentBalance = $user ? number_format((float) $user->credit_balance, 4) : '0.0000';

        $totalSpent = CreditTransaction::where('type', 'debit')->sum('amount');

        $todaySpent = CreditTransaction::where('type', 'debit')
            ->whereDate('created_at', today())
            ->sum('amount');

        $totalTransactions = CreditTransaction::where('type', 'debit')->count();

        $weekSpent = CreditTransaction::where('type', 'debit')
            ->where('created_at', '>=', now()->startOfWeek())
            ->sum('amount');

        return [
            Stat::make('Your Credit Balance', '$' . $currentBalance)
                ->description('Available credits')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color($user && $user->credit_balance > 1 ? 'success' : 'warning')
                ->chart($this->getBalanceHistory()),

            Stat::make('Total Cost Estimate', '$' . number_format((float) $totalSpent, 4))
                ->description('All-time spending across all users')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('danger'),

            Stat::make('Spent Today', '$' . number_format((float) $todaySpent, 4))
                ->description(number_format((float) $weekSpent, 4) . ' this week')
                ->descriptionIcon('heroicon-m-clock')
                ->color('primary'),

            Stat::make('Total Requests', number_format($totalTransactions))
                ->description('Total API calls')
                ->descriptionIcon('heroicon-m-bolt')
                ->color('info'),
        ];
    }

    protected function getBalanceHistory(): array
    {
        $user = auth()->user();
        if (!$user) {
            return [0];
        }

        $history = CreditTransaction::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderBy('created_at')
            ->pluck('balance_after')
            ->map(fn ($v) => (float) $v)
            ->toArray();

        return count($history) > 0 ? $history : [(float) $user->credit_balance];
    }
}
