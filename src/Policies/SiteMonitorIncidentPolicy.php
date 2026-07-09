<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Policies;

use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Illuminate\Database\Eloquent\Model;

final class SiteMonitorIncidentPolicy extends AbstractSiteMonitorResourcePolicy
{
    protected static function subject(): string
    {
        return 'SiteMonitorIncident';
    }

    protected function recordSiteId(Model $record): ?int
    {
        $siteId = parent::recordSiteId($record);

        if ($siteId !== null) {
            return $siteId;
        }

        $target = $record->getRelationValue('target');

        if (! $target instanceof SiteMonitorTarget) {
            return null;
        }

        $targetSiteId = $target->getAttribute('site_id');

        return is_numeric($targetSiteId) ? (int) $targetSiteId : null;
    }
}
