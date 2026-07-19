<?php

namespace App\Providers\Filament;

use App\Filament\User\Pages\Login;
use App\UserPortal\Pages\Dashboard;
use App\UserPortal\Pages\Domains;
use App\UserPortal\Pages\ImageGenerator;
use App\UserPortal\Pages\PdfGenerator;
use App\UserPortal\Pages\AddCredits;
use App\UserPortal\Pages\Profile;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class UserPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('user')
            ->path('portal')
            ->login(Login::class)   // redirects unauthenticated users to /login (Fortify)
            ->profile(Profile::class, isSimple: false)
            ->authGuard('web')
            ->brandLogo(asset('images/netkit_logo_cube.svg'))
            ->darkModeBrandLogo(asset('images/netkit_logo_cube.svg'))
            ->brandLogoHeight('3rem')
            ->favicon(asset('images/netkit_logo_cube.svg'))
            ->colors([
                'primary' => Color::Blue,
            ])
            ->discoverResources(in: app_path('Filament/User/Resources'), for: 'App\\Filament\\User\\Resources')
            ->discoverPages(in: app_path('Filament/User/Pages'), for: 'App\\Filament\\User\\Pages')
            ->pages([
                Dashboard::class,
                Domains::class,
                ImageGenerator::class,
                PdfGenerator::class,
                AddCredits::class,
            ])
            ->navigationItems([
                NavigationItem::make('Settings')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->activeIcon('heroicon-s-cog-6-tooth')
                    ->url(fn (): string => Profile::getUrl(panel: 'user'))
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.user.auth.profile'))
                    ->sort(99),
            ])
            ->discoverWidgets(in: app_path('Filament/User/Widgets'), for: 'App\\Filament\\User\\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureEmailIsVerified::class,
            ]);
    }
}
