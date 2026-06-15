<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static int run(?int $retentionDays = null)
 */
final class PruneSiteMonitorRunsAction
{
    use AsAction;

    public function handle(?int $retentionDays = null): int
    {
        $retentionDays ??= $this->integerConfig('capell-site-monitor.run_retention_days', 30);

        if ($retentionDays < 1) {
            return 0;
        }

        $protectedRunIds = $this->protectedRunIds();
        $cutoff = CarbonImmutable::now()->subDays($retentionDays);

        /** @var Builder<SiteMonitorRun> $query */
        $query = SiteMonitorRun::query()
            ->where('checked_at', '<', $cutoff);

        if ($protectedRunIds !== []) {
            $query->whereNotIn('id', $protectedRunIds);
        }

        $deleted = $query->delete();

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * @return list<int>
     */
    private function protectedRunIds(): array
    {
        $latestRunIds = SiteMonitorRun::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('target_id')
            ->pluck('id')
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id);

        $incidentRunIds = SiteMonitorIncident::query()
            ->whereNotNull('latest_run_id')
            ->pluck('latest_run_id')
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id);

        return array_values($latestRunIds
            ->merge($incidentRunIds)
            ->unique()
            ->values()
            ->all());
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
