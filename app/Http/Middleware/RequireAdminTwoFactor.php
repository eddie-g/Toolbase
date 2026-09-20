<?php

namespace App\Http\Middleware;

use App\Auth\AdminTwoFactor;
use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * In the admin panel a password is not enough: until this session has given a
 * code, every page leads to the challenge (or, for an account without a second
 * factor yet, to the setup). Runs after Filament's Authenticate.
 */
class RequireAdminTwoFactor
{
    private const OPEN_ROUTES = ['filament.admin.two-factor.challenge', 'filament.admin.two-factor.setup', 'filament.admin.auth.logout'];

    public function __construct(private AdminTwoFactor $twoFactor)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin || ! $this->twoFactor->isRequired()) {
            return $next($request);
        }
        if ($this->twoFactor->isEnrolled($admin) && $this->twoFactor->isVerified($request, $admin)) {
            return $next($request);
        }
        // The two pages themselves, signing out, and the Livewire requests those pages make.
        if (in_array($request->route()?->getName(), self::OPEN_ROUTES, true)
            || ($request->routeIs('livewire.update') && $this->livewireCallComesFromTwoFactorPage($request))) {
            return $next($request);
        }

        $target = route($this->twoFactor->isEnrolled($admin) ? 'filament.admin.two-factor.challenge' : 'filament.admin.two-factor.setup');
        if ($request->expectsJson() || $request->routeIs('livewire.update')) {
            abort(403, 'Two-factor authentication required.');
        }
        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->to($target);
    }

    private function livewireCallComesFromTwoFactorPage(Request $request): bool
    {
        $referer = (string) $request->headers->get('referer');
        $path = (string) parse_url($referer, PHP_URL_PATH);

        return in_array($path, [
            (string) parse_url(route('filament.admin.two-factor.challenge'), PHP_URL_PATH),
            (string) parse_url(route('filament.admin.two-factor.setup'), PHP_URL_PATH),
        ], true);
    }
}
