<?php

declare(strict_types=1);

use Capell\SiteMonitor\Actions\RecordSiteMonitorRunAction;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Events\SiteMonitorIncidentOpened;
use Capell\SiteMonitor\Events\SiteMonitorIncidentResolved;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Illuminate\Support\Facades\Event;

it('opens an incident only after the target failure threshold is reached', function (): void {
    Event::fake([
        SiteMonitorIncidentOpened::class,
        SiteMonitorIncidentResolved::class,
    ]);

    $target = createSiteMonitorTarget(['failure_threshold' => 2]);

    RecordSiteMonitorRunAction::run($target, siteMonitorResult(SiteMonitorState::Failing));

    expect(SiteMonitorIncident::query()->count())->toBe(0);
    Event::assertNotDispatched(SiteMonitorIncidentOpened::class);

    RecordSiteMonitorRunAction::run($target->refresh(), siteMonitorResult(SiteMonitorState::Failing));

    $incident = SiteMonitorIncident::query()->firstOrFail();

    expect($incident->status)->toBe(SiteMonitorIncidentStatus::Open)
        ->and($incident->failure_count)->toBe(2);

    Event::assertDispatched(SiteMonitorIncidentOpened::class, fn (SiteMonitorIncidentOpened $event): bool => $event->incident->is($incident)
            && $event->target->is($target)
            && $event->run->state === SiteMonitorState::Failing);
    Event::assertNotDispatched(SiteMonitorIncidentResolved::class);
});

it('resolves open incidents when the target passes again', function (): void {
    Event::fake([
        SiteMonitorIncidentOpened::class,
        SiteMonitorIncidentResolved::class,
    ]);

    $target = createSiteMonitorTarget(['failure_threshold' => 1]);

    RecordSiteMonitorRunAction::run($target, siteMonitorResult(SiteMonitorState::Failing));
    RecordSiteMonitorRunAction::run($target->refresh(), siteMonitorResult(SiteMonitorState::Passing));

    $incident = SiteMonitorIncident::query()->firstOrFail();

    expect($incident->status)->toBe(SiteMonitorIncidentStatus::Resolved)
        ->and($incident->resolved_at)->not->toBeNull();

    Event::assertDispatched(SiteMonitorIncidentOpened::class);
    Event::assertDispatched(SiteMonitorIncidentResolved::class, fn (SiteMonitorIncidentResolved $event): bool => $event->incident->is($incident)
            && $event->target->is($target)
            && $event->run->state === SiteMonitorState::Passing);
});

it('does not dispatch duplicate open events for existing open incidents', function (): void {
    Event::fake([
        SiteMonitorIncidentOpened::class,
        SiteMonitorIncidentResolved::class,
    ]);

    $target = createSiteMonitorTarget(['failure_threshold' => 1]);

    RecordSiteMonitorRunAction::run($target, siteMonitorResult(SiteMonitorState::Failing));
    RecordSiteMonitorRunAction::run($target->refresh(), siteMonitorResult(SiteMonitorState::Failing));

    expect(SiteMonitorIncident::query()->count())->toBe(1);

    Event::assertDispatchedTimes(SiteMonitorIncidentOpened::class, 1);
    Event::assertNotDispatched(SiteMonitorIncidentResolved::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createSiteMonitorTarget(array $overrides = []): SiteMonitorTarget
{
    return SiteMonitorTarget::query()->create([
        'name' => 'Example',
        'url' => 'https://example.com',
        'check_type' => SiteMonitorCheckType::HttpStatus,
        'interval_minutes' => 5,
        'timeout_ms' => 5000,
        'failure_threshold' => 2,
        'expected_status_minimum' => 200,
        'expected_status_maximum' => 399,
        'enabled' => true,
        'current_state' => SiteMonitorState::Unknown,
        ...$overrides,
    ]);
}

function siteMonitorResult(SiteMonitorState $state): SiteMonitorCheckResultData
{
    return new SiteMonitorCheckResultData(
        state: $state,
        statusCode: $state === SiteMonitorState::Passing ? 200 : 500,
        responseMs: 100,
        expiresAt: null,
        errorType: $state === SiteMonitorState::Failing ? 'unexpected_status_code' : null,
        errorMessage: $state === SiteMonitorState::Failing ? 'HTTP 500' : null,
    );
}
