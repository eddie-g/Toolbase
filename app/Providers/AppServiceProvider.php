<?php

namespace App\Providers;

use App\Support\ProductionConfig;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request: the guest-document claim runs once.
        $this->app->scoped(\App\Services\DocumentAccess::class);

        // One runner per process: the interpreter lookup is memoised on it.
        $this->app->singleton(\App\Services\PythonRunner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fail fast: production does not serve a request or start a worker
        // with unsafe settings or a missing secret (config/production.php).
        if ($this->app->isProduction()
            && config('production.enforce', true)
            && ProductionConfig::appliesTo($this->app->runningInConsole(), $this->artisanCommand())) {
            ProductionConfig::assertValid();
        }

        // Behind a TLS-terminating proxy every generated URL must be https,
        // or redirects and asset links fall back to plain http.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Twelve characters minimum everywhere; in production also refuse
        // passwords that appear in known breaches (a k-anonymity lookup, so
        // the password never leaves the server).
        Password::defaults(function () {
            $rule = Password::min(12);

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        \Illuminate\Support\Facades\Event::listen(
            \Laravel\Fortify\Events\TwoFactorAuthenticationChallenged::class,
            \App\Listeners\SendTwoFactorCodeListener::class
        );

        // Every sign-in, sign-out, failure, lockout and password reset on
        // either guard lands in auth_events (and last_login_* on the account).
        \Illuminate\Support\Facades\Event::listen([
            \Illuminate\Auth\Events\Login::class,
            \Illuminate\Auth\Events\Logout::class,
            \Illuminate\Auth\Events\Failed::class,
            \Illuminate\Auth\Events\Lockout::class,
            \Illuminate\Auth\Events\PasswordReset::class,
            \Illuminate\Auth\Events\OtherDeviceLogout::class,
        ], \App\Listeners\RecordAuthEvent::class);

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

    /** The artisan command being run ("horizon", "queue:work", ...), options skipped; null for web requests. */
    private function artisanCommand(): ?string
    {
        return $this->app->runningInConsole()
            ? (new \Symfony\Component\Console\Input\ArgvInput)->getFirstArgument()
            : null;
    }
}
