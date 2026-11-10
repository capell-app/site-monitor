<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Models;

use Capell\Core\Concerns\HasCapellTable;
use Capell\SiteMonitor\Actions\ValidateSiteMonitorStatusRangeAction;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\Concerns\RetainsTimestampInstants;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Override;

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
    use HasCapellTable;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use RetainsTimestampInstants;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'site_id',
        'language_id',
        'source_package',
        'source_key',
        'name',
        'url',
        'check_type',
        'route_name',
        'interval_minutes',
        'timeout_ms',
        'failure_threshold',
        'expected_status_minimum',
        'expected_status_maximum',
        'enabled',
        'current_state',
        'consecutive_failures',
        'last_checked_at',
        'next_check_at',
        'metadata',
    ];

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
        // Latest recorded evidence uses the same monotonic identity as retention;
        // wall-clock ordering reverses when daylight saving ends.
        return $this->hasOne(SiteMonitorRun::class, 'target_id')->latestOfMany('id');
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

    #[Override]
    protected static function booted(): void
    {
        self::saving(static function (self $target): void {
            if (! $target->exists || $target->isDirty(['expected_status_minimum', 'expected_status_maximum', 'check_type'])) {
                ValidateSiteMonitorStatusRangeAction::run($target);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    #[Override]
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
