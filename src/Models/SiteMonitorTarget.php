<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Models;

use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int|null $site_id
 * @property int|null $language_id
 * @property string $name
 * @property string $url
 * @property SiteMonitorCheckType $check_type
 * @property string|null $source_package
 * @property string|null $source_key
 * @property string|null $route_name
 * @property int $interval_minutes
 * @property int $timeout_ms
 * @property int $failure_threshold
 * @property int $expected_status_minimum
 * @property int $expected_status_maximum
 * @property bool $enabled
 * @property SiteMonitorState $current_state
 * @property int $consecutive_failures
 * @property CarbonImmutable|null $last_checked_at
 * @property CarbonImmutable|null $next_check_at
 * @property array<string, mixed>|null $metadata
 */
final class SiteMonitorTarget extends Model
{
    protected $guarded = [];

    /**
     * @return HasMany<SiteMonitorRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(SiteMonitorRun::class, 'target_id');
    }

    /**
     * @return HasOne<SiteMonitorRun, $this>
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(SiteMonitorRun::class, 'target_id')->latestOfMany('checked_at');
    }

    /**
     * @return HasMany<SiteMonitorIncident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(SiteMonitorIncident::class, 'target_id');
    }

    /**
     * @return HasOne<SiteMonitorIncident, $this>
     */
    public function openIncident(): HasOne
    {
        return $this->hasOne(SiteMonitorIncident::class, 'target_id')
            ->where('status', SiteMonitorIncidentStatus::Open->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check_type' => SiteMonitorCheckType::class,
            'enabled' => 'boolean',
            'current_state' => SiteMonitorState::class,
            'last_checked_at' => 'immutable_datetime',
            'next_check_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
