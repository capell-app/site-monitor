<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecordSiteMonitorRunAction
{
    use AsAction;

    public function handle(SiteMonitorTarget $target, SiteMonitorCheckResultData $result): SiteMonitorRun
    {
        $checkedAt = CarbonImmutable::now();

        $run = SiteMonitorRun::query()->create([
            'target_id' => $target->getKey(),
            'state' => $result->state,
            'status_code' => $result->statusCode,
            'response_ms' => $result->responseMs,
            'expires_at' => $result->expiresAt,
            'error_type' => $result->errorType,
            'error_message' => $result->errorMessage,
            'redirect_chain' => $result->redirectChain,
            'metadata' => $result->metadata,
            'checked_at' => $checkedAt,
        ]);

        $target->forceFill([
            'current_state' => $result->state,
            'consecutive_failures' => $result->state === SiteMonitorState::Failing
                ? ((int) $target->consecutive_failures) + 1
                : 0,
            'last_checked_at' => $checkedAt,
            'next_check_at' => $checkedAt->addMinutes((int) $target->interval_minutes),
        ])->save();

        ReconcileSiteMonitorIncidentAction::run($target->refresh(), $run);

        return $run;
    }
}
