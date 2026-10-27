<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * The timestamp casts used by workers before instant provenance was added.
 *
 * @property CarbonImmutable|null $last_checked_at
 * @property CarbonImmutable|null $next_check_at
 */
final class LegacySiteMonitorTimestampReader extends Model
{
    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'last_checked_at' => 'immutable_datetime',
            'next_check_at' => 'immutable_datetime',
        ];
    }
}
