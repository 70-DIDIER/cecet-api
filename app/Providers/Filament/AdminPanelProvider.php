<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\AuthenticateStaff;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName('CECT')
            ->brandLogo(asset('admin-assets/img/cect-logo.png'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('admin-assets/img/cect-logo.png'))
            ->defaultThemeMode(ThemeMode::Light)
            ->colors([
                'primary' => [
                    50 => '#ecfdf3',
                    100 => '#d3f8e1',
                    200 => '#a9efc7',
                    300 => '#70e0a6',
                    400 => '#36c97f',
                    500 => '#0b8a5a',
                    600 => '#0b6b45',
                    700 => '#09583a',
                    800 => '#094a31',
                    900 => '#073b28',
                    950 => '#04271a',
                ],
                'gray' => Color::Slate,
            ])
            ->font('Plus Jakarta Sans', url: asset('css/cect-fonts.css'), provider: LocalFontProvider::class)
            ->maxContentWidth(Width::SevenExtraLarge)
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups(['Pilotage', 'Opérations', 'Gestion', 'Données', 'Sécurité'])
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => new HtmlString('<link rel="stylesheet" href="'.asset('css/cect-admin.css').'">'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
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
                AuthenticateStaff::class,
            ]);
    }
}
