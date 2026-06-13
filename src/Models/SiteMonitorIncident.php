<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Models;

use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $target_id
 * @property int|null $latest_run_id
 * @property SiteMonitorIncidentStatus $status
 * @property string $severity
 * @property string $summary
 * @property int $failure_count
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $last_failure_at
 * @property CarbonImmutable|null $resolved_at
 * @property array<string, mixed>|null $metadata
 */
final class SiteMonitorIncident extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<SiteMonitorTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(SiteMonitorTarget::class, 'target_id');
    }

    /**
     * @return BelongsTo<SiteMonitorRun, $this>
     */
    public function latestRun(): BelongsTo
    {
        return $this->belongsTo(SiteMonitorRun::class, 'latest_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SiteMonitorIncidentStatus::class,
            'opened_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
