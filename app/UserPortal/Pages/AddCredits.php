<?php

namespace App\UserPortal\Pages;

use App\Models\CreditTransaction;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class AddCredits extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Add Credits';

    protected static ?string $title = 'Add Credits';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'user-portal.pages.add-credits';

    public function getViewData(): array
    {
        $user = Auth::user();

        return [
            'balance' => number_format((float) $user->credit_balance, 2),
            'amounts' => [3, 5, 10, 20, 50, 100],
            'transactions' => $user->creditTransactions()
                ->where('service', 'topup')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(),
        ];
    }
}
