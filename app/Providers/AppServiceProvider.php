<?php

namespace App\Providers;

use App\Support\ProductionConfig;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
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
        // One per request: the token issued for a first upload is reused until the cookie has gone out.
        $this->app->scoped(\App\Services\GuestDocuments::class);

        // One per request: refund() undoes what consume() took earlier in the same request.
        $this->app->scoped(\App\Services\UploadQuota::class);

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

        $this->registerEditorRateLimiters();

        // The editor's autosave: per editor (account, or guest session) and
        // document, with a looser per-address backstop for clients that drop
        // their cookies. An office behind one address shares only the backstop.
        RateLimiter::for('editor-autosave', function (Request $request) {
            $limits = (array) config('pdf_editor.autosave', []);
            $editor = Auth::guard('web')->id() !== null ? 'u'.Auth::guard('web')->id()
                : (Auth::guard('admin')->id() !== null ? 'a'.Auth::guard('admin')->id()
                : 's'.($request->hasSession() ? $request->session()->getId() : $request->ip()));
            $document = $request->route('document');
            $documentId = is_object($document) ? $document->getKey() : (string) $document;

            return [
                Limit::perMinute(max(1, (int) ($limits['saves_per_minute'] ?? 60)))->by("autosave|{$editor}|{$documentId}"),
                Limit::perMinute(max(1, (int) ($limits['saves_per_minute_per_ip'] ?? 600)))->by('autosave-ip|'.$request->ip()),
            ];
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

    /**
     * One limiter per class in config/editor_limits.php, named editor-<class>.
     * An account is limited as itself. A guest is limited per session and
     * address, and per address alone at a multiple, since a script can drop
     * its cookies. A refusal says what was limited and when to come back.
     */
    private function registerEditorRateLimiters(): void
    {
        foreach (array_keys((array) config('editor_limits.classes', [])) as $class) {
            RateLimiter::for("editor-{$class}", function (Request $request) use ($class) {
                $settings = (array) config("editor_limits.classes.{$class}", []);
                $scale = max(0.01, (float) config('editor_limits.scale', 1));
                $guestFactor = max(1, (int) config('editor_limits.guest_ip_factor', 4));
                $what = (string) ($settings['what'] ?? 'requests');

                $account = Auth::guard('web')->id() !== null ? 'u'.Auth::guard('web')->id()
                    : (Auth::guard('admin')->id() !== null ? 'a'.Auth::guard('admin')->id() : null);
                $refuse = function (Request $request, array $headers) use ($what) {
                    $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
                    $message = "Too many {$what} in a short time. Try again in {$seconds} seconds.";

                    if ($request->expectsJson()) {
                        return response()->json(['success' => false, 'code' => 'rate_limited', 'message' => $message, 'retry_after' => $seconds], 429, $headers);
                    }
                    // A form on one of the app's pages: back to it, with the
                    // message in the page's own error banner.
                    if (! $request->isMethod('GET') && $request->headers->has('referer')) {
                        return redirect()->back()->withErrors(['rate_limit' => $message])->withHeaders($headers);
                    }

                    return response($message, 429, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
                };

                $limits = [];
                foreach (['per_minute' => 'perMinute', 'per_day' => 'perDay'] as $setting => $window) {
                    if (empty($settings[$setting])) {
                        continue;
                    }
                    $max = max(1, (int) ceil($settings[$setting] * $scale));
                    if ($account !== null) {
                        $limits[] = Limit::$window($max)->by("editor-{$class}|{$setting}|{$account}")->response($refuse);

                        continue;
                    }
                    $session = $request->hasSession() ? $request->session()->getId() : 'none';
                    $limits[] = Limit::$window($max)->by("editor-{$class}|{$setting}|g|{$session}|{$request->ip()}")->response($refuse);
                    $limits[] = Limit::$window($max * $guestFactor)->by("editor-{$class}|{$setting}|ip|{$request->ip()}")->response($refuse);
                }

                return $limits;
            });
        }
    }

    /** The artisan command being run ("horizon", "queue:work", ...), options skipped; null for web requests. */
    private function artisanCommand(): ?string
    {
        return $this->app->runningInConsole()
            ? (new \Symfony\Component\Console\Input\ArgvInput)->getFirstArgument()
            : null;
    }
}
