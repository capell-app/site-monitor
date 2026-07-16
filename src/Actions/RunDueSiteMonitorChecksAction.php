<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Jobs\RunSiteMonitorTargetJob;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RunDueSiteMonitorChecksAction
{
    use AsFake;
    use AsObject;

    public function handle(bool $queue = true, ?int $siteId = null, ?int $targetId = null): int
    {
        $targets = ResolveSiteMonitorTargetsAction::run(siteId: $siteId, targetId: $targetId);

        foreach ($targets as $target) {
            if ($queue) {
                RunSiteMonitorTargetJob::dispatch($target->id);

                continue;
            }

            $result = RunSiteMonitorCheckAction::run($target);
            RecordSiteMonitorRunAction::run($target, $result);
        }

        return $targets->count();
    }
}
