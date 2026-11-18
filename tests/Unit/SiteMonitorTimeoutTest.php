<?php

declare(strict_types=1);

use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Jobs\RunSiteMonitorTargetJob;
use Capell\SiteMonitor\Support\LaravelSiteMonitorHttpClient;
use Capell\SiteMonitor\Support\SiteMonitorClock;
use Capell\SiteMonitor\Support\SiteMonitorTimeout;
use Capell\SiteMonitor\Tests\Fixtures\SiteMonitorTargetFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('caps a target timeout so the longest redirect chain fits inside the job timeout', function (): void {
    config()->set('capell-site-monitor.max_redirects', 5);

    $worstCaseMs = SiteMonitorTimeout::effectiveMs(600000) * (5 + 1);

    expect(SiteMonitorTimeout::effectiveMs(600000))->toBe(SiteMonitorTimeout::maximumMs())
        ->and($worstCaseMs)->toBeLessThanOrEqual(SiteMonitorTimeout::JOB_BUDGET_MS)
        ->and($worstCaseMs)->toBeLessThan((new RunSiteMonitorTargetJob(1))->timeout * 1000)
        ->and(SiteMonitorTimeout::effectiveMs(2000))->toBe(2000)
        ->and(SiteMonitorTimeout::effectiveMs(1))->toBe(SiteMonitorTimeout::MINIMUM_MS);
});

it('shrinks the cap when more redirects are allowed', function (): void {
    config()->set('capell-site-monitor.max_redirects', 9);

    expect(SiteMonitorTimeout::maximumMs())->toBe(5000);
});

it('sends HTTP checks with the capped timeout, not the stored one', function (): void {
    $timeouts = [];
    Http::fake(/** @param array<string, mixed> $options */ function (Request $request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'] ?? null;

        return Http::response('', 200);
    });

    (new LaravelSiteMonitorHttpClient)->check(SiteMonitorTargetFactory::make(SiteMonitorCheckType::HttpStatus, ['timeout_ms' => 600000]));

    expect($timeouts)->toHaveCount(1)
        ->and($timeouts[0] * 1000)->toBeLessThanOrEqual(SiteMonitorTimeout::maximumMs());
});

it('shares a fractional monotonic budget across HTTP redirects', function (float $elapsed, int $requests): void {
    config()->set('capell-site-monitor.allow_private_targets', true);
    $now = 100.0;
    $clock = Mockery::mock(SiteMonitorClock::class);
    $clock->shouldReceive('now')->andReturnUsing(static function () use (&$now): float {
        return $now;
    });
    app()->instance(SiteMonitorClock::class, $clock);
    $timeouts = [];
    Http::fake(/** @param array<string, mixed> $options */ function (Request $request, array $options) use (&$now, &$timeouts, $elapsed) {
        $timeouts[] = $options['timeout'];
        $now += $elapsed;

        return count($timeouts) === 1 ? Http::response('', 302, ['Location' => '/next']) : Http::response('', 200);
    });

    $result = (new LaravelSiteMonitorHttpClient)->check(SiteMonitorTargetFactory::make(SiteMonitorCheckType::HttpStatus, [
        'url' => 'https://93.184.216.34',
        'timeout_ms' => 500,
    ]));

    Http::assertSentCount($requests);
    expect($timeouts[0])->toBe(0.5);

    if ($requests === 2) {
        expect($timeouts[1])->toBe(0.25)
            ->and($result->state)->toBe(SiteMonitorState::Failing);
    }

    expect($result->errorType)->toBe('connection_error');
})->with([[0.25, 2], [0.5, 1]]);
