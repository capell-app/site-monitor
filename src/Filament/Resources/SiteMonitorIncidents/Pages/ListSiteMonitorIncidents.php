<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Filament\Resources\SiteMonitorIncidents\Pages;

use Capell\SiteMonitor\Filament\Resources\SiteMonitorIncidents\SiteMonitorIncidentResource;
use Filament\Resources\Pages\ListRecords;

final class ListSiteMonitorIncidents extends ListRecords
{
    protected static string $resource = SiteMonitorIncidentResource::class;
}
