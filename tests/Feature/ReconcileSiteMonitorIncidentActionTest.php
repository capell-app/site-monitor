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
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
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

it('retains failed runs and one incident when the opening listener throws', function (): void {
    useCommittedSiteMonitorTransactions();
    $target = createSiteMonitorTarget(['failure_threshold' => 1]);
    $levels = [];
    $errors = [];
    Event::listen(SiteMonitorIncidentOpened::class, function (SiteMonitorIncidentOpened $event) use (&$levels, $target): void {
        $levels[] = DB::transactionLevel();
        expect($event->target->is($target))->toBeTrue()
            ->and($event->incident->latest_run_id)->toBe($event->run->id)
            ->and($event->run->state)->toBe(SiteMonitorState::Failing);
        throw new RuntimeException('Opening listener failed');
    });

    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            RecordSiteMonitorRunAction::run($target, siteMonitorResult(SiteMonitorState::Failing));
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    expect(SiteMonitorRun::query()->count())->toBe(2)
        ->and(SiteMonitorIncident::query()->count())->toBe(1)
        ->and($target->refresh()->consecutive_failures)->toBe(2)
        ->and($target->current_state)->toBe(SiteMonitorState::Failing)
        ->and(SiteMonitorIncident::query()->sole()->failure_count)->toBe(2)
        ->and($errors)->toBe(['Opening listener failed'])
        ->and($levels)->toBe([0]);
});

it('retains recovery and avoids repeated resolution when the recovery listener throws', function (): void {
    useCommittedSiteMonitorTransactions();
    $target = createSiteMonitorTarget(['failure_threshold' => 1]);
    RecordSiteMonitorRunAction::run($target, siteMonitorResult(SiteMonitorState::Failing));
    $incident = SiteMonitorIncident::query()->sole();
    $levels = [];
    $errors = [];
    Event::listen(SiteMonitorIncidentResolved::class, function (SiteMonitorIncidentResolved $event) use (&$levels, $target, $incident): void {
        $levels[] = DB::transactionLevel();
        expect($event->target->is($target))->toBeTrue()
            ->and($event->incident->is($incident))->toBeTrue()
            ->and($event->incident->latest_run_id)->toBe($event->run->id)
            ->and($event->run->state)->toBe(SiteMonitorState::Passing);
        throw new RuntimeException('Recovery listener failed');
    });

    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            RecordSiteMonitorRunAction::run($target, siteMonitorResult(SiteMonitorState::Passing));
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    expect(SiteMonitorRun::query()->count())->toBe(3)
        ->and($target->refresh()->consecutive_failures)->toBe(0)
        ->and($target->current_state)->toBe(SiteMonitorState::Passing)
        ->and($incident->refresh()->status)->toBe(SiteMonitorIncidentStatus::Resolved)
        ->and($incident->resolved_at)->not->toBeNull()
        ->and($errors)->toBe(['Recovery listener failed'])
        ->and($levels)->toBe([0]);
});

it('defers automatic events until the outer transaction commits and discards them on rollback', function (bool $recovery): void {
    useCommittedSiteMonitorTransactions();
    $target = createSiteMonitorTarget(['failure_threshold' => 1]);
    if ($recovery) {
        RecordSiteMonitorRunAction::run($target, siteMonitorResult(SiteMonitorState::Failing));
    }

    $levels = [];
    Event::listen($recovery ? SiteMonitorIncidentResolved::class : SiteMonitorIncidentOpened::class, function () use (&$levels): void {
        $levels[] = DB::transactionLevel();
    });
    $state = $recovery ? SiteMonitorState::Passing : SiteMonitorState::Failing;
    $initialRuns = SiteMonitorRun::query()->count();

    DB::beginTransaction();
    RecordSiteMonitorRunAction::run($target, siteMonitorResult($state));
    expect($levels)->toBe([]);
    DB::rollBack();
    expect($levels)->toBe([])
        ->and(SiteMonitorRun::query()->count())->toBe($initialRuns)
        ->and($target->openIncident()->exists())->toBe($recovery);

    DB::beginTransaction();
    RecordSiteMonitorRunAction::run($target, siteMonitorResult($state));
    expect($levels)->toBe([]);
    DB::commit();
    expect($levels)->toBe([0])
        ->and(SiteMonitorRun::query()->count())->toBe($initialRuns + 1)
        ->and($target->openIncident()->exists())->toBe(! $recovery);
})->with([false, true]);

function useCommittedSiteMonitorTransactions(): void
{
    // Replace RefreshDatabase's wrapping transaction and testing callback manager
    // so these regressions exercise real commits in the isolated in-memory database.
    expect(DB::connection()->getDatabaseName())->toBe(':memory:')
        ->and(DB::transactionLevel())->toBe(1);
    DB::rollBack();
    $transactions = new DatabaseTransactionsManager;
    app()->instance('db.transactions', $transactions);
    DB::connection()->setTransactionManager($transactions);
}

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
