<?php

declare(strict_types=1);

use Capell\SiteMonitor\Actions\BuildSiteMonitorDashboardAction;
use Capell\SiteMonitor\Actions\BuildSiteMonitorEvidenceAction;
use Capell\SiteMonitor\Actions\OrderSiteMonitorTimestampsAction;
use Capell\SiteMonitor\Actions\PruneSiteMonitorRunsAction;
use Capell\SiteMonitor\Actions\RecordSiteMonitorRunAction;
use Capell\SiteMonitor\Actions\ResolveSiteMonitorTargetsAction;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorEvidenceStatus;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Health\SiteMonitorHealthCheck;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Capell\SiteMonitor\Tests\Fixtures\LegacySiteMonitorTimestampReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('keeps new SQL timestamps readable by older workers during overlap', function (string $timezone, string $checked): void {
    $originalTimezone = date_default_timezone_get();
    config()->set('app.timezone', $timezone);
    date_default_timezone_set($timezone);
    try {
        $checkedAt = CarbonImmutable::parse($checked);
        $nextCheckAt = $checkedAt->addMinutes(5);
        $target = timestampMonitorTarget();
        // Cover both supported instant inputs: DateTimeInterface and offset strings.
        $target->update(['last_checked_at' => $checkedAt, 'next_check_at' => $nextCheckAt->toIso8601String()]);
        $raw = $target->refresh()->getRawOriginal();
        $olderReader = new LegacySiteMonitorTimestampReader;
        $olderReader->setRawAttributes($raw);

        expect($raw['last_checked_at'])->toBe($checkedAt->setTimezone($timezone)->format('Y-m-d H:i:s'))
            ->and($raw['next_check_at'])->toBe($nextCheckAt->setTimezone($timezone)->format('Y-m-d H:i:s'))
            ->and($olderReader->last_checked_at?->equalTo($checkedAt))->toBeTrue()
            ->and($olderReader->next_check_at?->equalTo($nextCheckAt))->toBeTrue()
            ->and($target->last_checked_at?->equalTo($checkedAt))->toBeTrue()
            ->and($target->next_check_at?->equalTo($nextCheckAt))->toBeTrue()
            ->and(data_get($target->metadata, '_timestamp_instants.last_checked_at.utc'))->toBe($checkedAt->utc()->format('Y-m-d\TH:i:s.u\Z'))
            ->and(data_get($target->metadata, '_timestamp_instants.next_check_at.utc'))->toBe($nextCheckAt->utc()->format('Y-m-d\TH:i:s.u\Z'));
    } finally {
        date_default_timezone_set($originalTimezone);
    }
})->with([
    ['America/New_York', '2026-06-16T12:00:00Z'],
    ['America/New_York', '2026-01-16T12:00:00Z'],
    ['Europe/London', '2026-06-16T12:00:00Z'],
    ['Europe/London', '2026-01-16T12:00:00Z'],
    ['Europe/London', '2026-03-29T00:55:00Z'],
    ['Europe/London', '2026-03-29T01:06:00Z'],
    ['Europe/London', '2026-10-25T02:06:00Z'],
]);

it('selects the latest hundred runs at a same-second sample boundary', function (): void {
    $target = timestampMonitorTarget();
    $checkedAt = CarbonImmutable::parse('2026-06-16T12:00:00Z');
    for ($index = 1; $index <= 101; $index++) {
        SiteMonitorRun::query()->create([
            'target_id' => $target->getKey(), 'state' => SiteMonitorState::Passing,
            'checked_at' => $checkedAt->addMicroseconds($index * 1000),
            'response_ms' => $index <= 50 ? 1 : 101,
        ]);
    }

    expect(BuildSiteMonitorDashboardAction::run()->medianResponseMs)->toBe(101);
});

it('breaks identical run instant ties by identity at the sample boundary', function (): void {
    $target = timestampMonitorTarget();
    for ($index = 1; $index <= 101; $index++) {
        SiteMonitorRun::query()->create([
            'target_id' => $target->getKey(), 'state' => SiteMonitorState::Passing,
            'checked_at' => CarbonImmutable::parse('2026-06-16T12:00:00.123456Z'),
            'response_ms' => $index <= 50 ? 1 : 101,
        ]);
    }

    expect(BuildSiteMonitorDashboardAction::run()->medianResponseMs)->toBe(101);
});

it('ranks full-precision instants and identity ties before pagination and due batching', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-16T12:01:00Z'));
    $later = timestampMonitorTarget();
    $later->update(['last_checked_at' => CarbonImmutable::parse('2026-06-16T12:00:00.900000Z'), 'next_check_at' => CarbonImmutable::parse('2026-06-16T12:00:00.900000Z')]);
    $earlier = timestampMonitorTarget();
    $earlier->update(['last_checked_at' => CarbonImmutable::parse('2026-06-16T12:00:00.100000Z'), 'next_check_at' => CarbonImmutable::parse('2026-06-16T12:00:00.100000Z')]);
    $tied = timestampMonitorTarget();
    $tied->update(['last_checked_at' => $later->last_checked_at, 'next_check_at' => $later->next_check_at]);
    $never = timestampMonitorTarget();

    expect(OrderSiteMonitorTimestampsAction::run(SiteMonitorTarget::query(), 'last_checked_at', 'desc')->limit(2)->pluck('id')->all())->toBe([$tied->getKey(), $later->getKey()])
        ->and(OrderSiteMonitorTimestampsAction::run(SiteMonitorTarget::query(), 'last_checked_at', 'asc')->offset(1)->limit(2)->pluck('id')->all())->toBe([$earlier->getKey(), $later->getKey()]);
    config()->set('capell-site-monitor.batch_size', 2);
    expect(ResolveSiteMonitorTargetsAction::run()->pluck('id')->all())->toBe([$never->getKey(), $earlier->getKey()]);
});

it('retains exact check, schedule, expiry and incident instants across both clock changes', function (string $timezone, string $before, string $after): void {
    $originalTimezone = date_default_timezone_get();
    config()->set('app.timezone', $timezone);
    date_default_timezone_set($timezone);
    try {
        $this->travelTo(CarbonImmutable::parse($before)->setTimezone($timezone));
        $target = timestampMonitorTarget();
        $result = new SiteMonitorCheckResultData(SiteMonitorState::Failing, 503, 100, CarbonImmutable::parse($after), null, null, metadata: ['probe' => 'http']);
        $run = RecordSiteMonitorRunAction::run($target, $result)->refresh();
        $incident = SiteMonitorIncident::query()->firstOrFail();
        expect($run->toArray()['checked_at'])->toBe(CarbonImmutable::parse($before)->format('Y-m-d\TH:i:s.u\Z'))
            ->and($run->checked_at->utc()->toIso8601String())->toBe(CarbonImmutable::parse($before)->toIso8601String())
            ->and($run->expires_at?->utc()->toIso8601String())->toBe(CarbonImmutable::parse($after)->toIso8601String())
            ->and(data_get($run->metadata, '_timestamp_instants.checked_at.utc'))->toBe(CarbonImmutable::parse($before)->format('Y-m-d\TH:i:s.u\Z'))
            ->and($incident->opened_at->utc()->toIso8601String())->toBe(CarbonImmutable::parse($before)->toIso8601String())
            ->and($incident->last_failure_at?->equalTo($run->checked_at))->toBeTrue();

        // Repeated saves and replacing probe metadata retain the same provenance.
        $run->metadata = ['probe' => 'replacement'];
        $run->save();
        $stored = $run->refresh()->getRawOriginal();
        $run->save();
        expect($run->refresh()->getRawOriginal())->toBe($stored)
            ->and($run->refresh()->checked_at->equalTo(CarbonImmutable::parse($before)))->toBeTrue();

        $this->travelTo(CarbonImmutable::parse($after)->setTimezone($timezone));
        $target->refresh();
        expect($target->next_check_at?->equalTo(CarbonImmutable::parse($before)->addMinutes(5)))->toBeTrue()
            ->and(ResolveSiteMonitorTargetsAction::run()->pluck('id')->all())->toContain($target->getKey())
            ->and(BuildSiteMonitorEvidenceAction::run($target)->status)->toBe(SiteMonitorEvidenceStatus::Overdue)
            ->and(BuildSiteMonitorDashboardAction::run()->latestCheckedAt?->equalTo(CarbonImmutable::parse($before)))->toBeTrue();

        $passing = new SiteMonitorCheckResultData(SiteMonitorState::Passing, 200, 90, null, null, null);
        $latest = RecordSiteMonitorRunAction::run($target, $passing);
        expect($incident->refresh()->resolved_at?->equalTo(CarbonImmutable::parse($after)))->toBeTrue()
            ->and($target->refresh()->latestRun?->getKey())->toBe($latest->getKey())
            ->and(SiteMonitorIncident::query()->count())->toBe(1);
        RecordSiteMonitorRunAction::run($target, $passing);
        expect(SiteMonitorIncident::query()->count())->toBe(1)
            ->and($incident->refresh()->resolved_at?->equalTo(CarbonImmutable::parse($after)))->toBeTrue();
    } finally {
        $this->travelBack();
        date_default_timezone_set($originalTimezone);
    }
})->with(['UTC', 'Europe/London'])->with([
    ['2026-10-25T00:55:00+00:00', '2026-10-25T01:06:00+00:00'],
    ['2026-03-29T00:55:00+00:00', '2026-03-29T01:06:00+00:00'],
    ['2026-10-25T00:55:00.123456+00:00', '2026-10-25T01:06:00.654321+00:00'],
    ['2026-03-29T00:55:00.123456+00:00', '2026-03-29T01:06:00.654321+00:00'],
]);

it('treats uncertain legacy wall time as old evidence without rewriting the row', function (string $timezone, string $wall, string $instant, string $latest, string $now): void {
    $originalTimezone = date_default_timezone_get();
    config()->set('app.timezone', $timezone);
    config()->set('capell-site-monitor.max_stale_minutes', 10);
    date_default_timezone_set($timezone);
    try {
        $target = timestampMonitorTarget();
        DB::table('site_monitor_targets')->where('id', $target->getKey())->update([
            'last_checked_at' => $wall, 'next_check_at' => $wall, 'metadata' => json_encode(['owner' => 'legacy'], JSON_THROW_ON_ERROR),
        ]);
        $stored = $target->refresh()->getRawOriginal();
        $this->travelTo(CarbonImmutable::parse($now)->setTimezone($timezone));
        $target->refresh();
        expect($target->toArray()['last_checked_at'])->toBe(CarbonImmutable::parse($instant)->format('Y-m-d\TH:i:s.u\Z'))
            ->and($target->last_checked_at?->equalTo(CarbonImmutable::parse($instant)))->toBeTrue()
            ->and($target->latestPossibleTimestamp('last_checked_at')?->equalTo(CarbonImmutable::parse($latest)))->toBeTrue()
            ->and(BuildSiteMonitorEvidenceAction::run($target)->status)->toBe(SiteMonitorEvidenceStatus::Overdue)
            ->and(BuildSiteMonitorDashboardAction::run()->overdueTargets)->toBe(1)
            ->and(ResolveSiteMonitorTargetsAction::run()->pluck('id')->all())->toContain($target->getKey())
            ->and(SiteMonitorHealthCheck::runDiagnostics('site-monitor.target-freshness')->firstOrFail()->passed)->toBeFalse()
            ->and($target->refresh()->getRawOriginal())->toBe($stored);
    } finally {
        $this->travelBack();
        date_default_timezone_set($originalTimezone);
    }
})->with([
    ['UTC', '2026-10-25 00:55:00', '2026-10-25T00:55:00Z', '2026-10-25T00:55:00Z', '2026-10-25T01:06:00Z'],
    ['Europe/London', '2026-10-25 01:55:00', '2026-10-25T00:55:00Z', '2026-10-25T01:55:00Z', '2026-10-25T01:06:00Z'],
    ['Europe/London', '2026-03-29 01:55:00', '2026-03-29T00:55:00Z', '2026-03-29T01:55:00Z', '2026-03-29T01:06:00Z'],
    ['Europe/London', '2026-06-16 12:00:00', '2026-06-16T11:00:00Z', '2026-06-16T11:00:00Z', '2026-06-16T11:11:00Z'],
]);

it('preserves assigned spring-gap wall times as overdue evidence after saving and rehydrating', function (): void {
    $originalTimezone = date_default_timezone_get();
    config()->set('app.timezone', 'Europe/London');
    date_default_timezone_set('Europe/London');
    try {
        $this->travelTo(CarbonImmutable::parse('2026-03-29T01:56:00Z'));
        $target = timestampMonitorTarget();
        $nextCheckAt = CarbonImmutable::parse('2026-03-29T02:01:00Z');
        $target->update([
            'last_checked_at' => CarbonImmutable::parse('2026-03-29T01:55:00Z'),
            'next_check_at' => $nextCheckAt,
            'metadata' => ['owner' => 'legacy'],
        ]);
        expect(data_get($target->metadata, '_timestamp_instants.last_checked_at'))->not->toBeNull();

        $target->setAttribute('last_checked_at', '2026-03-29 01:55:00');
        $target->save();
        $target->refresh();

        expect($target->getRawOriginal('last_checked_at'))->toBe('2026-03-29 01:55:00')
            ->and($target->last_checked_at?->utc()->toIso8601String())->toBe('2026-03-29T00:55:00+00:00')
            ->and($target->latestPossibleTimestamp('last_checked_at')?->utc()->toIso8601String())->toBe('2026-03-29T01:55:00+00:00')
            ->and(BuildSiteMonitorEvidenceAction::run($target)->status)->toBe(SiteMonitorEvidenceStatus::Overdue)
            ->and(data_get($target->metadata, '_timestamp_instants.last_checked_at'))->toBeNull()
            ->and($target->next_check_at?->equalTo($nextCheckAt))->toBeTrue()
            ->and(data_get($target->metadata, '_timestamp_instants.next_check_at.utc'))->toBe('2026-03-29T02:01:00.000000Z')
            ->and(data_get($target->metadata, 'owner'))->toBe('legacy');
    } finally {
        $this->travelBack();
        date_default_timezone_set($originalTimezone);
    }
});

it('retains ambiguous legacy runs until every possible instant is outside retention', function (): void {
    $originalTimezone = date_default_timezone_get();
    config()->set('app.timezone', 'Europe/London');
    date_default_timezone_set('Europe/London');
    try {
        $target = timestampMonitorTarget();
        $legacy = SiteMonitorRun::query()->create(['target_id' => $target->getKey(), 'state' => SiteMonitorState::Passing, 'checked_at' => CarbonImmutable::parse('2026-10-25T00:55:00Z')]);
        DB::table('site_monitor_runs')->where('id', $legacy->getKey())->update(['checked_at' => '2026-10-25 01:55:00', 'metadata' => null]);
        SiteMonitorRun::query()->create(['target_id' => $target->getKey(), 'state' => SiteMonitorState::Passing, 'checked_at' => CarbonImmutable::parse('2026-10-26T01:00:00Z')]);
        $this->travelTo(CarbonImmutable::parse('2026-10-26T01:06:00Z'));
        expect(PruneSiteMonitorRunsAction::run(1))->toBe(0)->and($legacy->fresh())->not->toBeNull();
        $this->travelTo(CarbonImmutable::parse('2026-10-26T02:00:00Z'));
        expect(PruneSiteMonitorRunsAction::run(1))->toBe(1)
            ->and(PruneSiteMonitorRunsAction::run(1))->toBe(0);
    } finally {
        $this->travelBack();
        date_default_timezone_set($originalTimezone);
    }
});

it('invalidates stale instant provenance for raw and offset-free legacy updates', function (string $timezone): void {
    $originalTimezone = date_default_timezone_get();
    config()->set('app.timezone', $timezone);
    date_default_timezone_set($timezone);
    try {
        $target = timestampMonitorTarget();
        $target->update(['last_checked_at' => CarbonImmutable::parse('2026-10-25T00:55:00Z')]);
        $target->metadata = null;
        $target->save();
        expect($target->refresh()->last_checked_at?->equalTo(CarbonImmutable::parse('2026-10-25T00:55:00Z')))->toBeTrue();

        DB::table('site_monitor_targets')->where('id', $target->getKey())->update(['last_checked_at' => '2026-10-24 12:00:00']);
        expect($target->refresh()->last_checked_at?->equalTo(CarbonImmutable::parse('2026-10-24 12:00:00', $timezone)))->toBeTrue();

        $target->update(['last_checked_at' => '2026-10-25 01:55:00']);
        expect($target->refresh()->last_checked_at?->utc()->format('H:i'))->toBe($timezone === 'UTC' ? '01:55' : '00:55')
            ->and($target->latestPossibleTimestamp('last_checked_at')?->utc()->format('H:i'))->toBe('01:55');
        $target->update(['last_checked_at' => null]);
        expect($target->refresh()->last_checked_at)->toBeNull()
            ->and(data_get($target->metadata, '_timestamp_instants.last_checked_at'))->toBeNull();
    } finally {
        date_default_timezone_set($originalTimezone);
    }
})->with(['UTC', 'Europe/London']);

it('orders mixed legacy and UTC dashboard evidence by instant before taking the recent sample', function (): void {
    $originalTimezone = date_default_timezone_get();
    config()->set('app.timezone', 'Europe/London');
    date_default_timezone_set('Europe/London');
    try {
        $legacyTarget = timestampMonitorTarget();
        DB::table('site_monitor_targets')->where('id', $legacyTarget->getKey())->update(['last_checked_at' => '2026-10-25 01:55:00']);
        $modernTarget = timestampMonitorTarget();
        $modernTarget->update(['last_checked_at' => CarbonImmutable::parse('2026-10-25T01:06:00Z')]);
        for ($index = 1; $index <= 100; $index++) {
            SiteMonitorRun::query()->create([
                'target_id' => $modernTarget->getKey(), 'state' => SiteMonitorState::Passing,
                'checked_at' => CarbonImmutable::parse('2026-10-25T00:56:00Z')->addSeconds($index * 6), 'response_ms' => $index,
            ]);
        }

        $legacyRun = SiteMonitorRun::query()->create([
            'target_id' => $legacyTarget->getKey(), 'state' => SiteMonitorState::Passing,
            'checked_at' => '2026-10-25 01:55:00', 'response_ms' => 999,
        ]);
        $legacyIncident = SiteMonitorIncident::query()->create([
            'target_id' => $legacyTarget->getKey(), 'latest_run_id' => $legacyRun->getKey(),
            'status' => 'open', 'summary' => 'Legacy incident', 'opened_at' => '2026-10-25 01:55:00',
        ]);
        SiteMonitorIncident::query()->create([
            'target_id' => $modernTarget->getKey(), 'status' => 'open', 'summary' => 'Modern incident',
            'opened_at' => CarbonImmutable::parse('2026-10-25T01:06:00Z'),
        ]);

        $dashboard = BuildSiteMonitorDashboardAction::run();
        expect($dashboard->medianResponseMs)->toBe(50)
            ->and($dashboard->latestCheckedAt?->equalTo(CarbonImmutable::parse('2026-10-25T01:06:00Z')))->toBeTrue()
            ->and($dashboard->oldestOpenIncidentAt?->equalTo($legacyIncident->opened_at))->toBeTrue()
            ->and(OrderSiteMonitorTimestampsAction::run(SiteMonitorTarget::query(), 'last_checked_at', 'desc')->pluck('id')->all())->toBe([$modernTarget->getKey(), $legacyTarget->getKey()])
            ->and(OrderSiteMonitorTimestampsAction::run(SiteMonitorTarget::query(), 'last_checked_at', 'asc')->pluck('id')->all())->toBe([$legacyTarget->getKey(), $modernTarget->getKey()])
            ->and(OrderSiteMonitorTimestampsAction::run(SiteMonitorIncident::query(), 'opened_at', 'asc')->firstOrFail()->getKey())->toBe($legacyIncident->getKey())
            ->and(OrderSiteMonitorTimestampsAction::run(SiteMonitorTarget::query()->whereKey($modernTarget->getKey()), 'last_checked_at', 'asc')->pluck('id')->all())->toBe([$modernTarget->getKey()]);
    } finally {
        date_default_timezone_set($originalTimezone);
    }
});

function timestampMonitorTarget(): SiteMonitorTarget
{
    return SiteMonitorTarget::query()->create([
        'name' => 'Clock monitor', 'url' => 'https://example.test', 'check_type' => SiteMonitorCheckType::HttpStatus,
        'interval_minutes' => 5, 'failure_threshold' => 1, 'enabled' => true, 'current_state' => SiteMonitorState::Passing,
    ]);
}
