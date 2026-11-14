<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Fixtures\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Ivanange\Billing\Filament\BillingPlugin;
use Ivanange\Billing\Tests\Fixtures\Member;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->resources([MemberResource::class])
            ->plugin(BillingPlugin::make()->billables([Member::class => 'Members']))
            ->middleware([EncryptCookies::class, StartSession::class, ShareErrorsFromSession::class])
            ->authMiddleware([Authenticate::class]);
    }
}
