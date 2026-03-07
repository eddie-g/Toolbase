<?php

namespace App\UserPortal\Widgets;

use App\Models\CreditTransaction;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UserCreditBalanceWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected function getStats(): array
    {
        $user = auth()->user();

        if (!$user) {
            return [
                Stat::make('Your Credit Balance', '$0.0000')
                    ->description('Available credits')
                    ->descriptionIcon('heroicon-m-currency-dollar')
                    ->color('warning'),
            ];
        }

        $userId = $user->id;
        $currentBalance = number_format((float) $user->credit_balance, 4);

        $todaySpent = CreditTransaction::query()
            ->where('user_id', $userId)
            ->where('type', 'debit')
            ->whereDate('created_at', today())
            ->sum('amount');

        $weekSpent = CreditTransaction::query()
            ->where('user_id', $userId)
            ->where('type', 'debit')
            ->where('created_at', '>=', now()->startOfWeek())
            ->sum('amount');

        $totalRequests = CreditTransaction::query()
            ->where('user_id', $userId)
            ->where('type', 'debit')
            ->count();

        return [
            Stat::make('Your Credit Balance', '$' . $currentBalance)
                ->description('Available credits')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color((float) $user->credit_balance > 1 ? 'success' : 'warning')
                ->chart($this->getBalanceHistory($userId, (float) $user->credit_balance)),

            Stat::make('Spent Today', '$' . number_format((float) $todaySpent, 4))
                ->description(number_format((float) $weekSpent, 4) . ' this week')
                ->descriptionIcon('heroicon-m-clock')
                ->color('primary'),

            Stat::make('Total Requests', number_format($totalRequests))
                ->description('Total API calls')
                ->descriptionIcon('heroicon-m-bolt')
                ->color('info'),
        ];
    }

    protected function getBalanceHistory(int $userId, float $fallbackBalance): array
    {
        $history = CreditTransaction::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderBy('created_at')
            ->pluck('balance_after')
            ->map(fn ($v) => (float) $v)
            ->toArray();

        return count($history) > 0 ? $history : [$fallbackBalance];
    }
}
