<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Tests;

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Core\Facades\CapellCore;
use Capell\SiteMonitor\Providers\AdminServiceProvider as SiteMonitorAdminServiceProvider;
use Capell\SiteMonitor\Providers\SiteMonitorServiceProvider;
use Capell\Tests\AbstractTestCase;
use Livewire\LivewireServiceProvider;
use Override;

class SiteMonitorTestCase extends AbstractTestCase
{
    protected function getPackageServiceName(): string
    {
        return 'capell-site-monitor';
    }

    /**
     * @return class-string[]
     */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            ...parent::getPackageProviders($app),
            AdminServiceProvider::class,
            AdminPanelProvider::class,
            LivewireServiceProvider::class,
            SiteMonitorServiceProvider::class,
            SiteMonitorAdminServiceProvider::class,
        ];
    }

    #[Override]
    protected function getEnvironmentSetUp(mixed $app): void
    {
        parent::getEnvironmentSetUp($app);

        CapellCore::forcePackageInstalled(AdminServiceProvider::$packageName);
        CapellCore::forcePackageInstalled(SiteMonitorServiceProvider::$packageName);

        config()->set('cache.default', 'array');
        config()->set('queue.default', 'sync');
        config()->set('capell-site-monitor.schedule_enabled', false);
    }
}
