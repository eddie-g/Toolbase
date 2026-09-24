<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
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
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::registerView(fn () => view('auth.register'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Three buckets, so credential stuffing from many addresses against one
        // account and many accounts from one address both hit a wall, while a
        // shared office NAT still gets fifty tries an hour.
        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::username())));
            $ip = (string) $request->ip();

            // A refused attempt is a lockout for the audit trail, and the
            // client gets a 429 with Retry-After.
            $lockedOut = function (Request $request, array $headers) {
                event(new Lockout($request));

                return response()->json([
                    'message' => 'Too many login attempts. Please try again later.',
                    'errors' => ['email' => ['Too many login attempts. Please try again later.']],
                ], 429, $headers);
            };

            // A load test signs thousands of accounts in from a handful of
            // addresses (tests/Load). The multiplier is ignored in production.
            $scale = app()->environment('production') ? 1 : max(1, (int) config('security.login_throttle_scale', 1));

            return [
                Limit::perMinute(5 * $scale)->by($email.'|'.$ip)->response($lockedOut),
                Limit::perHour(20 * $scale)->by('login-email:'.sha1($email))->response($lockedOut),
                Limit::perHour(50 * $scale)->by('login-ip:'.$ip)->response($lockedOut),
            ];
        });

        RateLimiter::for('two-factor', function (Request $request) {
            $challenged = $request->session()->get('login.id');

            return Limit::perMinute(5)->by(($challenged !== null ? 'user:'.$challenged : 'anon').'|'.$request->ip());
        });

        // Applied by App\Http\Middleware\ProtectAuthForms to the Fortify
        // registration and password-reset forms.
        RateLimiter::for('register', function (Request $request) {
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by('register-ip:'.$ip),
                Limit::perDay(20)->by('register-ip-day:'.$ip),
            ];
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(3)->by('forgot-ip:'.$request->ip()),
                Limit::perHour(5)->by('forgot-email:'.sha1($email)),
            ];
        });
    }
}
