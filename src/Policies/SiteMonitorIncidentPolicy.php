<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Policies;

use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Illuminate\Database\Eloquent\Model;
use Override;

final class SiteMonitorIncidentPolicy extends AbstractSiteMonitorResourcePolicy
{
    #[Override]
    protected static function subject(): string
    {
        return 'SiteMonitorIncident';
    }

    #[Override]
    protected function recordSiteId(Model $record): ?int
    {
        $target = $record->getRelationValue('target');

        if (! $target instanceof SiteMonitorTarget) {
            return null;
        }

        $targetSiteId = $target->getAttribute('site_id');

        return is_numeric($targetSiteId) ? (int) $targetSiteId : null;
    }
}
