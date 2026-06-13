<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\Pages;

use Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\SiteMonitorTargetResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSiteMonitorTarget extends CreateRecord
{
    protected static string $resource = SiteMonitorTargetResource::class;
}
