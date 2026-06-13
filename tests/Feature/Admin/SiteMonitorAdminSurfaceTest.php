<?php

declare(strict_types=1);

use Capell\SiteMonitor\Filament\Pages\SiteMonitorDashboardPage;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorIncidents\SiteMonitorIncidentResource;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\SiteMonitorTargetResource;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorTarget;

it('exposes the Site Monitor admin page and resources when installed', function (): void {
    expect(SiteMonitorDashboardPage::shouldRegisterNavigation())->toBeTrue()
        ->and(SiteMonitorTargetResource::shouldRegisterNavigation())->toBeTrue()
        ->and(SiteMonitorIncidentResource::shouldRegisterNavigation())->toBeTrue()
        ->and(SiteMonitorTargetResource::getModel())->toBe(SiteMonitorTarget::class)
        ->and(SiteMonitorIncidentResource::getModel())->toBe(SiteMonitorIncident::class);
});
