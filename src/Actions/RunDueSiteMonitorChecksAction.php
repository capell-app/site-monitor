<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Jobs\RunSiteMonitorTargetJob;
use Lorisleiva\Actions\Concerns\AsAction;

final class RunDueSiteMonitorChecksAction
{
    use AsAction;

    public function handle(bool $queue = true, ?int $siteId = null, ?int $targetId = null): int
    {
        $targets = (new ResolveSiteMonitorTargetsAction)->handle(siteId: $siteId, targetId: $targetId);

        foreach ($targets as $target) {
            if ($queue) {
                RunSiteMonitorTargetJob::dispatch($target->id);

                continue;
            }

            $result = (new RunSiteMonitorCheckAction)->handle($target);
            (new RecordSiteMonitorRunAction)->handle($target, $result);
        }

        return $targets->count();
    }
}
