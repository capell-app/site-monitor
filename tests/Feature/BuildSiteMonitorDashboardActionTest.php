<?php

declare(strict_types=1);

use Capell\SiteMonitor\Actions\BuildSiteMonitorDashboardAction;
use Capell\SiteMonitor\Actions\Fixtures\PrepareSiteMonitorScreenshotAction;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;

it('seeds distinct target and incident capture records without scheduling a network check', function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    $previousFixture = getenv('CAPELL_SCREENSHOT_FIXTURE');
    $previousAppPath = getenv('CAPELL_SCREENSHOT_APP_PATH');
    putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');
    putenv('CAPELL_SCREENSHOT_APP_PATH=' . base_path());

    try {
        $action = new PrepareSiteMonitorScreenshotAction;
        $target = $action->handle();
        expect($action->handle()->getKey())->toBe($target->getKey())
            ->and($target->name)->toBe('Screenshot homepage')
            ->and($target->enabled)->toBeFalse()
            ->and($target->openIncident()->firstOrFail()->summary)->toBe('Screenshot homepage returned a demo 503 response.')
            ->and($target->incidents()->count())->toBe(1);
        $dashboard = BuildSiteMonitorDashboardAction::run();
        expect($dashboard->failingTargets)->toBe(1)->and($dashboard->openIncidents)->toBe(1);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    } finally {
        putenv($previousFixture === false ? 'CAPELL_SCREENSHOT_FIXTURE' : 'CAPELL_SCREENSHOT_FIXTURE=' . $previousFixture);
        putenv($previousAppPath === false ? 'CAPELL_SCREENSHOT_APP_PATH' : 'CAPELL_SCREENSHOT_APP_PATH=' . $previousAppPath);
    }
});

it('refuses missing or foreign disposable app paths before seeding any monitor records', function (): void {
    $previousFixture = getenv('CAPELL_SCREENSHOT_FIXTURE');
    $previousAppPath = getenv('CAPELL_SCREENSHOT_APP_PATH');
    $counts = [SiteMonitorTarget::query()->count(), SiteMonitorRun::query()->count(), SiteMonitorIncident::query()->count()];

    try {
        putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');
        foreach ([null, '/tmp/site-monitor-unselected-checkout'] as $appPath) {
            putenv($appPath === null ? 'CAPELL_SCREENSHOT_APP_PATH' : 'CAPELL_SCREENSHOT_APP_PATH=' . $appPath);
            expect(fn (): SiteMonitorTarget => (new PrepareSiteMonitorScreenshotAction)->handle())
                ->toThrow(RuntimeException::class, 'Site Monitor screenshot fixtures require the selected disposable screenshot environment.');
            expect([SiteMonitorTarget::query()->count(), SiteMonitorRun::query()->count(), SiteMonitorIncident::query()->count()])
                ->toBe($counts);
        }
    } finally {
        putenv($previousFixture === false ? 'CAPELL_SCREENSHOT_FIXTURE' : 'CAPELL_SCREENSHOT_FIXTURE=' . $previousFixture);
        putenv($previousAppPath === false ? 'CAPELL_SCREENSHOT_APP_PATH' : 'CAPELL_SCREENSHOT_APP_PATH=' . $previousAppPath);
    }
});

it('replaces only fixture target runs when repeated after the capture minute changes', function (): void {
    $previousFixture = getenv('CAPELL_SCREENSHOT_FIXTURE');
    $previousAppPath = getenv('CAPELL_SCREENSHOT_APP_PATH');
    putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');
    putenv('CAPELL_SCREENSHOT_APP_PATH=' . base_path());

    try {
        $foreignTarget = createDashboardSiteMonitorTarget('Existing customer target', SiteMonitorState::Passing);
        $foreignRun = createDashboardSiteMonitorRun($foreignTarget, 175);
        $foreignIncident = SiteMonitorIncident::query()->create([
            'target_id' => $foreignTarget->getKey(),
            'latest_run_id' => $foreignRun->getKey(),
            'status' => SiteMonitorIncidentStatus::Resolved,
            'severity' => 'warning',
            'summary' => 'Existing customer incident',
            'failure_count' => 1,
            'opened_at' => CarbonImmutable::parse('2026-06-16 08:00:00'),
            'last_failure_at' => CarbonImmutable::parse('2026-06-16 08:05:00'),
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-06-16 12:00:00'));
        $action = new PrepareSiteMonitorScreenshotAction;
        $target = $action->handle();
        expect(SiteMonitorRun::query()->where('target_id', $target->getKey())->count())->toBe(4);

        $this->travelTo(CarbonImmutable::parse('2026-06-16 12:02:00'));
        expect($action->handle()->getKey())->toBe($target->getKey())
            ->and(SiteMonitorRun::query()->where('target_id', $target->getKey())->count())->toBe(4)
            ->and(SiteMonitorRun::query()->where('target_id', $foreignTarget->getKey())->pluck('id')->all())->toBe([$foreignRun->getKey()])
            ->and($foreignIncident->fresh()?->latest_run_id)->toBe($foreignRun->getKey())
            ->and($foreignTarget->fresh()?->name)->toBe('Existing customer target');
    } finally {
        $this->travelBack();
        putenv($previousFixture === false ? 'CAPELL_SCREENSHOT_FIXTURE' : 'CAPELL_SCREENSHOT_FIXTURE=' . $previousFixture);
        putenv($previousAppPath === false ? 'CAPELL_SCREENSHOT_APP_PATH' : 'CAPELL_SCREENSHOT_APP_PATH=' . $previousAppPath);
    }
});

it('refuses an occupied screenshot target source key without adopting it', function (): void {
    withSiteMonitorCaptureEnvironment(function (): void {
        $foreign = createDashboardSiteMonitorTarget('Foreign authored monitor', SiteMonitorState::Passing, [
            'source_key' => 'screenshot-homepage',
            'source_package' => 'capell-app/site-monitor',
        ]);
        expect(fn (): SiteMonitorTarget => (new PrepareSiteMonitorScreenshotAction)->handle())
            ->toThrow(RuntimeException::class, 'belongs to another record');
        expect($foreign->fresh()?->name)->toBe('Foreign authored monitor')
            ->and(SiteMonitorTarget::query()->count())->toBe(1)
            ->and(SiteMonitorRun::query()->count())->toBe(0)
            ->and(SiteMonitorIncident::query()->count())->toBe(0);
    });
});

it('rejects a foreign run and referenced incident on its own target without deleting either', function (): void {
    withSiteMonitorCaptureEnvironment(function (): void {
        $target = (new PrepareSiteMonitorScreenshotAction)->handle();
        $foreignRun = createDashboardSiteMonitorRun($target, 777);
        $foreignIncident = SiteMonitorIncident::query()->create([
            'target_id' => $target->getKey(),
            'latest_run_id' => $foreignRun->getKey(),
            'status' => SiteMonitorIncidentStatus::Resolved,
            'severity' => 'warning',
            'summary' => 'Customer incident on the same target',
            'failure_count' => 1,
            'opened_at' => CarbonImmutable::parse('2026-06-16 08:00:00'),
        ]);
        $runIds = SiteMonitorRun::query()->where('target_id', $target->getKey())->orderBy('id')->pluck('id')->all();
        expect(fn (): SiteMonitorTarget => (new PrepareSiteMonitorScreenshotAction)->handle())
            ->toThrow(RuntimeException::class, 'foreign run');
        expect(SiteMonitorRun::query()->where('target_id', $target->getKey())->orderBy('id')->pluck('id')->all())->toBe($runIds)
            ->and($foreignRun->fresh()?->response_ms)->toBe(777)
            ->and($foreignIncident->fresh()?->latest_run_id)->toBe($foreignRun->getKey());
    });
});

it('rejects a foreign incident on an otherwise owned screenshot target', function (): void {
    withSiteMonitorCaptureEnvironment(function (): void {
        $target = (new PrepareSiteMonitorScreenshotAction)->handle();
        $foreign = SiteMonitorIncident::query()->create([
            'target_id' => $target->getKey(),
            'status' => SiteMonitorIncidentStatus::Open,
            'severity' => 'critical',
            'summary' => 'A real incident added after preparation',
            'failure_count' => 2,
            'opened_at' => CarbonImmutable::parse('2026-06-16 08:00:00'),
        ]);
        $runIds = SiteMonitorRun::query()->where('target_id', $target->getKey())->orderBy('id')->pluck('id')->all();
        expect(fn (): SiteMonitorTarget => (new PrepareSiteMonitorScreenshotAction)->handle())
            ->toThrow(RuntimeException::class, 'foreign incident');
        expect(SiteMonitorRun::query()->where('target_id', $target->getKey())->orderBy('id')->pluck('id')->all())->toBe($runIds)
            ->and($foreign->fresh()?->summary)->toBe('A real incident added after preparation');
    });
});

function withSiteMonitorCaptureEnvironment(Closure $callback): void
{
    $fixture = getenv('CAPELL_SCREENSHOT_FIXTURE');
    $path = getenv('CAPELL_SCREENSHOT_APP_PATH');
    putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');
    putenv('CAPELL_SCREENSHOT_APP_PATH=' . base_path());
    try {
        $callback();
    } finally {
        putenv($fixture === false ? 'CAPELL_SCREENSHOT_FIXTURE' : 'CAPELL_SCREENSHOT_FIXTURE=' . $fixture);
        putenv($path === false ? 'CAPELL_SCREENSHOT_APP_PATH' : 'CAPELL_SCREENSHOT_APP_PATH=' . $path);
    }
}

it('keeps dashboard aggregation inside the declared admin query budget', function (): void {
    $passingTarget = createDashboardSiteMonitorTarget('Passing homepage', SiteMonitorState::Passing, [
        'last_checked_at' => CarbonImmutable::parse('2026-06-16 09:00:00'),
    ]);
    $warningTarget = createDashboardSiteMonitorTarget('Slow checkout', SiteMonitorState::Warning);
    $failingTarget = createDashboardSiteMonitorTarget('Down docs', SiteMonitorState::Failing, [
        'enabled' => false,
    ]);

    createDashboardSiteMonitorRun($passingTarget, 100);
    createDashboardSiteMonitorRun($warningTarget, 900);
    $latestFailingRun = createDashboardSiteMonitorRun($failingTarget, 200);

    SiteMonitorIncident::query()->create([
        'target_id' => $failingTarget->id,
        'latest_run_id' => $latestFailingRun->id,
        'status' => SiteMonitorIncidentStatus::Open,
        'severity' => 'critical',
        'summary' => 'Down docs is failing.',
        'failure_count' => 2,
        'opened_at' => CarbonImmutable::parse('2026-06-16 08:00:00'),
        'last_failure_at' => CarbonImmutable::parse('2026-06-16 08:05:00'),
    ]);

    $queryCount = 0;
    DB::listen(static function () use (&$queryCount): void {
        $queryCount++;
    });

    $dashboard = BuildSiteMonitorDashboardAction::run();

    expect($queryCount)->toBeLessThanOrEqual(20)
        ->and($dashboard->totalTargets)->toBe(3)
        ->and($dashboard->enabledTargets)->toBe(2)
        ->and($dashboard->passingTargets)->toBe(1)
        ->and($dashboard->warningTargets)->toBe(1)
        ->and($dashboard->failingTargets)->toBe(1)
        ->and($dashboard->openIncidents)->toBe(1)
        ->and($dashboard->medianResponseMs)->toBe(200)
        ->and($dashboard->oldestOpenIncidentAt?->toDateTimeString())->toBe('2026-06-16 08:00:00')
        ->and($dashboard->latestCheckedAt?->toDateTimeString())->toBe('2026-06-16 09:00:00');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createDashboardSiteMonitorTarget(string $name, SiteMonitorState $state, array $overrides = []): SiteMonitorTarget
{
    return SiteMonitorTarget::query()->create([
        'name' => $name,
        'url' => 'https://example.com',
        'check_type' => SiteMonitorCheckType::HttpStatus,
        'interval_minutes' => 5,
        'timeout_ms' => 5000,
        'failure_threshold' => 2,
        'expected_status_minimum' => 200,
        'expected_status_maximum' => 399,
        'enabled' => true,
        'current_state' => $state,
        ...$overrides,
    ]);
}

function createDashboardSiteMonitorRun(SiteMonitorTarget $target, int $responseMs): SiteMonitorRun
{
    return SiteMonitorRun::query()->create([
        'target_id' => $target->id,
        'state' => $target->current_state,
        'status_code' => $target->current_state === SiteMonitorState::Passing ? 200 : 500,
        'response_ms' => $responseMs,
        'checked_at' => CarbonImmutable::parse('2026-06-16 09:00:00')->subSeconds($responseMs),
    ]);
}

it('refuses an authenticated actor other than the selected capture identity before writes', function (): void {
    $previous = [];
    foreach (['CAPELL_SCREENSHOT_FIXTURE', 'CAPELL_SCREENSHOT_APP_PATH', 'CAPELL_SCREENSHOT_ADMIN_EMAIL'] as $key) {
        $previous[$key] = getenv($key);
    }

    putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');
    putenv('CAPELL_SCREENSHOT_APP_PATH=' . base_path());
    putenv('CAPELL_SCREENSHOT_ADMIN_EMAIL=selected-capture@example.test');
    $actor = new User;
    $actor->forceFill(['id' => 987654, 'email' => 'another-actor@example.test']);
    $this->actingAs($actor);
    $before = SiteMonitorTarget::query()->count();

    try {
        expect(fn (): SiteMonitorTarget => (new PrepareSiteMonitorScreenshotAction)->handle())->toThrow(RuntimeException::class, 'selected capture actor');
        expect(SiteMonitorTarget::query()->count())->toBe($before);
    } finally {
        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }
});

it('rejects a different physical checkout and production even when fixture mode is selected', function (): void {
    $previous = [getenv('CAPELL_SCREENSHOT_FIXTURE'), getenv('CAPELL_SCREENSHOT_APP_PATH')];
    $environment = config('app.env');
    $runtimeEnvironment = app()->environment();
    $actor = new User;
    $before = SiteMonitorTarget::query()->count();
    putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');
    try {
        foreach (['foreign-checkout', 'production'] as $state) {
            putenv('CAPELL_SCREENSHOT_APP_PATH=' . ($state === 'foreign-checkout' ? __DIR__ : base_path()));
            config()->set('app.env', $state === 'production' ? 'production' : 'testing');
            app()->instance('env', $state === 'production' ? 'production' : 'testing');
            expect(fn (): SiteMonitorTarget => (new PrepareSiteMonitorScreenshotAction)->handle())->toThrow(RuntimeException::class, 'selected disposable screenshot environment');
            expect(SiteMonitorTarget::query()->count())->toBe($before);
        }
    } finally {
        config()->set('app.env', $environment);
        app()->instance('env', $runtimeEnvironment);
        foreach (['CAPELL_SCREENSHOT_FIXTURE', 'CAPELL_SCREENSHOT_APP_PATH'] as $index => $key) {
            putenv($previous[$index] === false ? $key : $key . '=' . $previous[$index]);
        }
    }
});

it('preserves a foreign target incident that references a fixture run', function (): void {
    withSiteMonitorCaptureEnvironment(function (): void {
        $action = new PrepareSiteMonitorScreenshotAction;
        $target = $action->handle();
        $run = SiteMonitorRun::query()->where('target_id', $target->getKey())->firstOrFail();
        $foreignTarget = $target->replicate()->forceFill(['source_key' => 'customer-target', 'metadata' => []]);
        $foreignTarget->save();

        $incident = SiteMonitorIncident::query()->create([
            'target_id' => $foreignTarget->getKey(), 'latest_run_id' => $run->getKey(),
            'summary' => 'Customer incident', 'status' => SiteMonitorIncidentStatus::Open,
            'severity' => 'warning', 'failure_count' => 1, 'opened_at' => now(),
        ]);
        $before = $incident->fresh()?->getRawOriginal();
        $runIds = SiteMonitorRun::query()->where('target_id', $target->getKey())->orderBy('id')->pluck('id')->all();
        expect(fn (): SiteMonitorTarget => $action->handle())->toThrow(RuntimeException::class, 'foreign incident references');
        expect($incident->fresh()?->getRawOriginal())->toBe($before)
            ->and(SiteMonitorRun::query()->where('target_id', $target->getKey())->orderBy('id')->pluck('id')->all())->toBe($runIds);
    });
});

it('registers the fixture command and refuses forced seeding in production', function (): void {
    $environment = config('app.env');
    $runtimeEnvironment = app()->environment();
    $previous = [getenv('CAPELL_SCREENSHOT_FIXTURE'), getenv('CAPELL_SCREENSHOT_APP_PATH')];
    $before = SiteMonitorTarget::query()->count();
    config()->set('app.env', 'production');
    app()->instance('env', 'production');
    putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');
    putenv('CAPELL_SCREENSHOT_APP_PATH=' . base_path());
    try {
        $pending = $this->artisan('capell:site-monitor-screenshot-fixture', ['--force' => true]);
        throw_unless($pending instanceof PendingCommand, RuntimeException::class, 'Expected the interactive Artisan test command.');
        // Execute before the capture environment is restored.
        $pending->expectsOutputToContain('disposable')->assertFailed()->run();
        expect(SiteMonitorTarget::query()->count())->toBe($before);
    } finally {
        config()->set('app.env', $environment);
        app()->instance('env', $runtimeEnvironment);
        foreach (['CAPELL_SCREENSHOT_FIXTURE', 'CAPELL_SCREENSHOT_APP_PATH'] as $index => $key) {
            putenv($previous[$index] === false ? $key : $key . '=' . $previous[$index]);
        }
    }
});

it('refuses unresolved physical paths before any fixture work', function (): void {
    $base = base_path();
    $path = getenv('CAPELL_SCREENSHOT_APP_PATH');
    $fixture = getenv('CAPELL_SCREENSHOT_FIXTURE');
    $missing = $base . '/missing-screenshot-checkout';
    putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');
    putenv('CAPELL_SCREENSHOT_APP_PATH=' . $missing);
    app()->setBasePath($missing);
    try {
        expect(fn (): SiteMonitorTarget => (new PrepareSiteMonitorScreenshotAction)->handle())->toThrow(RuntimeException::class, 'selected disposable screenshot environment');
    } finally {
        app()->setBasePath($base);
        putenv($path === false ? 'CAPELL_SCREENSHOT_APP_PATH' : 'CAPELL_SCREENSHOT_APP_PATH=' . $path);
        putenv($fixture === false ? 'CAPELL_SCREENSHOT_FIXTURE' : 'CAPELL_SCREENSHOT_FIXTURE=' . $fixture);
    }
});
