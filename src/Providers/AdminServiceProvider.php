<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Providers;

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Capell\SiteMonitor\Filament\Pages\SiteMonitorDashboardPage;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorIncidents\SiteMonitorIncidentResource;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\SiteMonitorTargetResource;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Capell\SiteMonitor\Policies\SiteMonitorIncidentPolicy;
use Capell\SiteMonitor\Policies\SiteMonitorTargetPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Override;

final class AdminServiceProvider extends ServiceProvider
{
    use RegistersInstalledRuntime;

    #[Override]
    public function register(): void
    {
        $this->registerInstalledRuntime(SiteMonitorServiceProvider::$packageName, 'admin');
    }

    protected function bootInstalledRuntime(): void
    {
        $this->registerAdminSurface();
        $this->registerPolicies();
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

    private function registerPolicies(): void
    {
        Gate::policy(SiteMonitorIncident::class, SiteMonitorIncidentPolicy::class);
        Gate::policy(SiteMonitorTarget::class, SiteMonitorTargetPolicy::class);
    }
}
