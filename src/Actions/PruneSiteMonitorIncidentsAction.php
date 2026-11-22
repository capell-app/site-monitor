<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Deletes resolved incidents once they are older than the configured retention.
 * Open incidents are never pruned, whatever their age.
 *
 * @method static int run(?int $retentionDays = null)
 */
final class PruneSiteMonitorIncidentsAction
{
    use AsFake;
    use AsObject;

    public function handle(?int $retentionDays = null): int
    {
        $retentionDays ??= $this->integerConfig('capell-site-monitor.incident_retention_days', 365);

        if ($retentionDays < 1) {
            return 0;
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays($retentionDays);
        $deleted = 0;

        foreach (SiteMonitorIncident::query()->where('status', SiteMonitorIncidentStatus::Resolved->value)->lazyById() as $incident) {
            // Delete only when every possible legacy occurrence is expired.
            if ($incident->latestPossibleTimestamp('resolved_at')?->lessThan($cutoff) === true) {
                $deleted += $incident->delete() ? 1 : 0;
            }
        }

        return $deleted;
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
