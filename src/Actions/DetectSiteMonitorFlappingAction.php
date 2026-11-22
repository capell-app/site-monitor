<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Events\SiteMonitorTargetFlapping;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Marks a target flapping when its up/down state changed at least
 * `flap_change_threshold` times within its last `flap_window_runs` runs, and
 * dispatches one event on entering that condition. It clears once the window calms.
 */
final class DetectSiteMonitorFlappingAction
{
    use AsFake;
    use AsObject;

    public function handle(SiteMonitorTarget $target, SiteMonitorRun $run): void
    {
        $window = $this->integerConfig('capell-site-monitor.flap_window_runs', 10);
        $threshold = $this->integerConfig('capell-site-monitor.flap_change_threshold', 4);

        if ($window < 2 || $threshold < 1) {
            return;
        }

        $failing = SiteMonitorRun::query()
            ->where('target_id', $target->getKey())
            ->orderByDesc('id')
            ->limit($window)
            ->get(['state'])
            ->map(static fn (SiteMonitorRun $recent): bool => $recent->state === SiteMonitorState::Failing)
            ->values();

        $changes = 0;
        foreach ($failing as $index => $isFailing) {
            if ($index > 0 && $isFailing !== $failing[$index - 1]) {
                $changes++;
            }
        }

        $flapping = $changes >= $threshold;

        if ($flapping === $target->is_flapping) {
            return;
        }

        $target->forceFill(['is_flapping' => $flapping])->save();

        if ($flapping) {
            DB::afterCommit(static fn () => event(new SiteMonitorTargetFlapping($target, $run, $changes)));
        }
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
