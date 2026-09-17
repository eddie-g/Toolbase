<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Behind a TLS-terminating proxy every generated URL must be https,
        // or redirects and asset links fall back to plain http.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        \Illuminate\Support\Facades\Event::listen(
            \Laravel\Fortify\Events\TwoFactorAuthenticationChallenged::class,
            \App\Listeners\SendTwoFactorCodeListener::class
        );

        // Register Filament user-portal widgets in a custom namespace so
        // Livewire can resolve them when rendered from the user dashboard.
        Livewire::component(
            'app.user-portal.widgets.user-credit-balance-widget',
            \App\UserPortal\Widgets\UserCreditBalanceWidget::class
        );
        Livewire::component(
            'app.user-portal.widgets.user-usage-chart-widget',
            \App\UserPortal\Widgets\UserUsageChartWidget::class
        );
        Livewire::component(
            'app.user-portal.widgets.user-recent-transactions-widget',
            \App\UserPortal\Widgets\UserRecentTransactionsWidget::class
        );
        Livewire::component(
            'app.user-portal.widgets.user-recent-domain-searches-widget',
            \App\UserPortal\Widgets\UserRecentDomainSearchesWidget::class
        );
        Livewire::component(
            'app.user-portal.widgets.user-favorite-domains-widget',
            \App\UserPortal\Widgets\UserFavoriteDomainsWidget::class
        );
        Livewire::component(
            'app.user-portal.widgets.user-uploaded-pdfs-widget',
            \App\UserPortal\Widgets\UserUploadedPdfsWidget::class
        );
        Livewire::component(
            'app.user-portal.widgets.user-pdf-commands-widget',
            \App\UserPortal\Widgets\UserPdfCommandsWidget::class
        );
    }
}
