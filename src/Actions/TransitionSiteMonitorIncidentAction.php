<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Data\SiteMonitorIncidentTransitionData;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Events\SiteMonitorIncidentManuallyTransitioned;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class TransitionSiteMonitorIncidentAction
{
    use AsFake;
    use AsObject;

    public function handle(SiteMonitorIncident $incident, SiteMonitorIncidentStatus $status, string $reason): SiteMonitorIncident
    {
        return DB::transaction(function () use ($incident, $status, $reason): SiteMonitorIncident {
            SiteMonitorTarget::query()->whereKey($incident->target_id)->lockForUpdate()->firstOrFail();
            $current = SiteMonitorIncident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $current);
            $actorId = auth()->id();
            abort_unless(is_int($actorId) || is_string($actorId), 403);

            if ($current->status === $status) {
                return $current;
            }

            $reason = trim($reason);
            Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();
            if ($status === SiteMonitorIncidentStatus::Open && $current->target()->firstOrFail()->openIncident()->exists()) {
                throw ValidationException::withMessages(['reason' => __('capell-site-monitor::package.validation.incident_already_open')]);
            }

            $transition = new SiteMonitorIncidentTransitionData(
                fromStatus: $current->status,
                toStatus: $status,
                actorId: (string) $actorId,
                reason: $reason,
                occurredAt: CarbonImmutable::now('UTC'),
            );
            $metadata = $current->metadata ?? [];
            $history = $metadata['operator_transitions'] ?? [];
            $history = is_array($history) ? array_values($history) : [];
            $history[] = $transition->toArray();
            $metadata['operator_transitions'] = $history;
            $current->forceFill([
                'status' => $status,
                'resolved_at' => $status === SiteMonitorIncidentStatus::Resolved ? $transition->occurredAt : null,
                'metadata' => $metadata,
            ])->save();

            DB::afterCommit(static fn () => event(new SiteMonitorIncidentManuallyTransitioned($current, $transition)));

            return $current;
        });
    }
}
