<?php

namespace App\Filament\Pages;

use App\Models\MonthlyPlan;
use App\Models\UserSubscription;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class Subscriptions extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Subscriptions';

    protected static ?string $title = 'Subscriptions';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.subscriptions';

    public function getViewData(): array
    {
        $user = Auth::user();
        $plans = MonthlyPlan::active()->orderBy('price')->get();

        $activeSubscriptions = UserSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->with('plan')
            ->get()
            ->keyBy(fn ($sub) => $sub->plan->product_key);

        return [
            'plans' => $plans,
            'activeSubscriptions' => $activeSubscriptions,
            'checkoutUrl' => route('subscription.checkout'),
            'cancelUrl' => route('subscription.cancel'),
        ];
    }
}
