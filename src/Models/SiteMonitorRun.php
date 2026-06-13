<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Models;

use Capell\SiteMonitor\Enums\SiteMonitorState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $target_id
 * @property SiteMonitorState $state
 * @property int|null $status_code
 * @property int|null $response_ms
 * @property CarbonImmutable|null $expires_at
 * @property string|null $error_type
 * @property string|null $error_message
 * @property array<int, string>|null $redirect_chain
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $checked_at
 */
final class SiteMonitorRun extends Model
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => SiteMonitorState::class,
            'expires_at' => 'immutable_datetime',
            'redirect_chain' => 'array',
            'metadata' => 'array',
            'checked_at' => 'immutable_datetime',
        ];
    }
}
