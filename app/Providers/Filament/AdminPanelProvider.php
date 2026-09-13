<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The dashboard restaurant staff actually use.
 *
 * Two audiences share this panel and they want opposite things. The person who
 * forked this repo wants to see every field, because they are wiring it to a
 * client. The person answering the phone at 8pm on a Friday wants to see
 * tonight's orders and nothing else. Where the two conflict, the second wins —
 * they are the ones using it every day.
 *
 * Navigation is grouped rather than flat for the same reason. A takeaway
 * manager edits the menu once a week and looks at orders forty times a night,
 * so orders sit at the top on their own and everything else is filed behind a
 * heading.
 *
 * @see docs/DECISIONS.md #0025
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('restaurantline')
            // Amber reads as "kitchen" rather than "SaaS", and more usefully it
            // stays legible under the warm lighting and grease-filmed screens
            // that real kitchen hardware lives behind.
            ->colors([
                'primary' => Color::Amber,
            ])
            ->navigationGroups([
                'Menu',
                'People',
                'Settings',
            ])
            /*
             * The kitchen display is a route rather than a panel page, so it
             * needs saying out loud here — otherwise the only way to reach the
             * screen the kitchen lives on is to know the URL, and the person
             * who deployed this will not be there on Friday night to type it.
             *
             * It opens in a new tab: a wall-mounted screen that has been
             * navigated away from is a screen somebody has to walk over to.
             */
            ->navigationItems([
                NavigationItem::make('Kitchen display')
                    ->url('/kitchen', shouldOpenInNewTab: true)
                    ->icon(Heroicon::OutlinedTv)
                    ->sort(3),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
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
            ]);
    }
}
