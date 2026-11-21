<?php

namespace App\Providers\Filament;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login()
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ], isRequired: true)
            ->darkMode(true, true)
            ->colors([
                'primary' => [
                    50 => '#fff4ec',
                    100 => '#ffe5d2',
                    200 => '#ffcaa5',
                    300 => '#ffa86e',
                    400 => '#ff8a47',
                    500 => '#ff8a47',
                    600 => '#ff6a13',
                    700 => '#d9540a',
                    800 => '#a84209',
                    900 => '#6e2c08',
                    950 => '#3a1604',
                ],
                // Brand neutrals: paper 950, surface 900, surface-2 800, line 700, faint 500, muted 400, ink 100.
                'gray' => [
                    50 => '#f8f7f4',
                    100 => '#f3f2ee',
                    200 => '#e4e3de',
                    300 => '#c9c8c2',
                    400 => '#a3a39e',
                    500 => '#82817a',
                    600 => '#5c5c58',
                    700 => '#333331',
                    800 => '#242423',
                    900 => '#1b1b1a',
                    950 => '#121212',
                ],
                'danger' => Color::hex('#ff6b78'),
                'success' => Color::hex('#4fd18f'),
            ])
            ->font('Schibsted Grotesk', provider: LocalFontProvider::class)
            ->brandLogo(asset('brand/logo-one-line-dark.svg'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('brand/favicon.svg'))
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
