<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Data;

use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class SiteMonitorIncidentAlertData extends Data
{
    public const string EVENT_RESOLVED = 'site_monitor.incident.resolved';

    public const string EVENT_FLAPPING = 'site_monitor.target.flapping';

    public function __construct(
        public readonly string $event,
        public readonly ?int $incidentId,
        public readonly int $targetId,
        public readonly string $targetName,
        public readonly string $targetUrl,
        public readonly string $state,
        public readonly ?int $statusCode,
        public readonly ?int $responseMs,
        public readonly ?string $errorType,
        public readonly int $failureCount,
        public readonly string $checkedAt,
        public readonly string $openedAt,
        public readonly ?string $resolvedAt,
    ) {}

    public static function fromIncident(SiteMonitorIncident $incident, SiteMonitorTarget $target, SiteMonitorRun $run, bool $recovered): self
    {
        return new self(
            event: $recovered ? self::EVENT_RESOLVED : 'site_monitor.incident.opened',
            incidentId: $incident->id,
            targetId: $target->id,
            targetName: $target->name,
            targetUrl: self::origin($target->url),
            state: $run->state->value,
            statusCode: $run->status_code,
            responseMs: $run->response_ms,
            errorType: $run->error_type,
            failureCount: $incident->failure_count,
            checkedAt: $run->checked_at->toIso8601String(),
            openedAt: $incident->opened_at->toIso8601String(),
            resolvedAt: $incident->resolved_at?->toIso8601String(),
        );
    }

    public static function fromFlapping(SiteMonitorTarget $target, SiteMonitorRun $run, int $changes): self
    {
        return new self(
            event: self::EVENT_FLAPPING,
            incidentId: null,
            targetId: $target->id,
            targetName: $target->name,
            targetUrl: self::origin($target->url),
            state: $run->state->value,
            statusCode: $run->status_code,
            responseMs: $run->response_ms,
            errorType: $run->error_type,
            failureCount: $changes,
            checkedAt: $run->checked_at->toIso8601String(),
            openedAt: $run->checked_at->toIso8601String(),
            resolvedAt: null,
        );
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
