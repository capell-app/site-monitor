<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\Pages;

use Capell\SiteMonitor\Actions\AuthoriseSiteMonitorTargetSiteAction;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\SiteMonitorTargetResource;
use Filament\Resources\Pages\CreateRecord;
use Override;

final class CreateSiteMonitorTarget extends CreateRecord
{
    protected static string $resource = SiteMonitorTargetResource::class;

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        AuthoriseSiteMonitorTargetSiteAction::run(is_numeric($data['site_id'] ?? null) ? (int) $data['site_id'] : null);

        return $data;
    }
}
