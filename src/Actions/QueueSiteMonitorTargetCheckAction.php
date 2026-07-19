<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Jobs\RunSiteMonitorTargetJob;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class QueueSiteMonitorTargetCheckAction
{
    use AsFake;
    use AsObject;

    public function handle(SiteMonitorTarget $target): void
    {
        $targetId = $target->getKey();
        throw_unless(is_int($targetId), RuntimeException::class, 'Site monitor target must have an integer key.');
        RunSiteMonitorTargetJob::dispatch($targetId);
    }
}
