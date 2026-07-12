<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\Pages;

use Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\SiteMonitorTargetResource;
use Filament\Resources\Pages\EditRecord;

final class EditSiteMonitorTarget extends EditRecord
{
    protected static string $resource = SiteMonitorTargetResource::class;
}
