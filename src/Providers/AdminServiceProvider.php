<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Providers;

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Facades\CapellCore;
use Capell\SiteMonitor\Filament\Pages\SiteMonitorDashboardPage;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorIncidents\SiteMonitorIncidentResource;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\SiteMonitorTargetResource;
use Illuminate\Support\ServiceProvider;
use Override;

final class AdminServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        if (! $this->isPackageInstalled()) {
            return;
        }

        $this->registerAdminSurface();
    }

    public function boot(): void
    {
        if (! $this->isPackageInstalled()) {
            return;
        }

        $this->registerAdminSurface();
    }

    private function isPackageInstalled(): bool
    {
        return CapellCore::isPackageInstalled(SiteMonitorServiceProvider::$packageName);
    }

    private function registerAdminSurface(): void
    {
        if (! class_exists(CapellAdmin::class)) {
            return;
        }

        CapellAdmin::registerExtensionPage(SiteMonitorServiceProvider::$packageName, SiteMonitorDashboardPage::class);
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource(
            class: SiteMonitorTargetResource::class,
            group: 'SiteMonitorTarget',
        ));
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource(
            class: SiteMonitorIncidentResource::class,
            group: 'SiteMonitorIncident',
        ));
    }
}
