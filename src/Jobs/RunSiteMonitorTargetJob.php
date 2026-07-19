<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Jobs;

use Capell\SiteMonitor\Actions\RecordSiteMonitorRunAction;
use Capell\SiteMonitor\Actions\RunSiteMonitorCheckAction;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RunSiteMonitorTargetJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120];

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

    public function uniqueId(): string
    {
        return (string) $this->targetId;
    }

    public function failed(?Throwable $throwable): void
    {
        $target = SiteMonitorTarget::query()->find($this->targetId);

        if (! $target instanceof SiteMonitorTarget || ! $target->enabled) {
            return;
        }

        RecordSiteMonitorRunAction::run($target, new SiteMonitorCheckResultData(
            state: SiteMonitorState::Failing,
            statusCode: null,
            responseMs: null,
            expiresAt: null,
            errorType: 'monitor_job_failed',
            errorMessage: $throwable?->getMessage() ?? 'Monitor check job failed.',
        ));
    }
}
