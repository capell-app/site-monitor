<?php

declare(strict_types=1);

use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

it('persists metadata written through mass assignment on create', function (): void {
    $target = SiteMonitorTarget::query()->create([
        'name' => 'Homepage',
        'url' => 'https://example.test/',
        'check_type' => SiteMonitorCheckType::HttpStatus,
        'interval_minutes' => 5,
        'timeout_ms' => 5000,
        'failure_threshold' => 2,
        'expected_status_minimum' => 200,
        'expected_status_maximum' => 399,
        'enabled' => true,
        'metadata' => ['owner' => 'ops', 'runbook' => 'https://example.test/runbook'],
    ]);

    expect($target->refresh()->metadata)->toBe([
        'owner' => 'ops',
        'runbook' => 'https://example.test/runbook',
    ]);
});

it('persists metadata written through mass assignment on update', function (): void {
    $target = SiteMonitorTarget::query()->create([
        'name' => 'Homepage',
        'url' => 'https://example.test/',
        'check_type' => SiteMonitorCheckType::HttpStatus,
        'interval_minutes' => 5,
        'timeout_ms' => 5000,
        'failure_threshold' => 2,
        'expected_status_minimum' => 200,
        'expected_status_maximum' => 399,
        'enabled' => true,
    ]);

    $target->update(['metadata' => ['owner' => 'ops']]);

    expect($target->refresh()->metadata)->toBe(['owner' => 'ops']);
});

/**
 * Narrowing `$guarded = []` to an explicit `$fillable` is a silent change: a column
 * left off the list is dropped by mass assignment with no error outside strict mode.
 * Every site-monitor column is written by an Action or the admin form, so none of
 * these tables currently has a legitimate non-fillable column. If a future column is
 * genuinely written only via `forceFill()` or the database, add it to the skip list
 * below with a reason rather than deleting this test.
 */
it('keeps every site-monitor column mass-assignable', function (string $model): void {
    /** @var Model $instance */
    $instance = new $model;

    $alwaysSkipped = ['id', 'created_at', 'updated_at', 'deleted_at'];
    $columns = array_values(array_diff(
        Schema::getColumnListing($instance->getTable()),
        $alwaysSkipped,
    ));

    expect($columns)->not->toBeEmpty();

    $notFillable = array_values(array_filter(
        $columns,
        static fn (string $column): bool => ! $instance->isFillable($column),
    ));

    expect($notFillable)->toBe([]);
})->with([
    SiteMonitorTarget::class,
    SiteMonitorRun::class,
    SiteMonitorIncident::class,
]);
