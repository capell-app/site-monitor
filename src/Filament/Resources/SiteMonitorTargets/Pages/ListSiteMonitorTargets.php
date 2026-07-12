<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\Pages;

use Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\SiteMonitorTargetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;

final class ListSiteMonitorTargets extends ListRecords
{
    protected static string $resource = SiteMonitorTargetResource::class;

    /**
     * @return array<int, CreateAction>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
