<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Jobs;

use Capell\SiteMonitor\Actions\RecordSiteMonitorRunAction;
use Capell\SiteMonitor\Actions\RunSiteMonitorCheckAction;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RunSiteMonitorTargetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $targetId,
    ) {}

    public function handle(): void
    {
        $target = SiteMonitorTarget::query()->find($this->targetId);

        if (! $target instanceof SiteMonitorTarget || ! $target->enabled) {
            return;
        }

        $result = RunSiteMonitorCheckAction::run($target);
        RecordSiteMonitorRunAction::run($target, $result);
    }
}
