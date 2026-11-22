<?php

declare(strict_types=1);

use Capell\Core\Contracts\Security\ResolvesHostAddresses;
use Capell\Core\Support\Http\ProcessHostAddressResolver;
use Capell\SiteMonitor\Actions\RunSiteMonitorCheckAction;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Support\LaravelSiteMonitorHttpClient;
use Capell\SiteMonitor\Tests\Fixtures\SiteMonitorTargetFactory;
use Illuminate\Support\Facades\Http;

/*
 * The system resolver takes no timeout. A DNS server that stalls must not hold
 * a check past the target's own timeout: the lookup is abandoned at the
 * check's deadline and the check records a timeout, well inside the queue
 * job's 60 second limit. Without the deadline each lookup waited for Core's
 * default lookup limit and the check reported an unresolvable host instead.
 */

beforeEach(function (): void {
    app()->instance(ResolvesHostAddresses::class, new ProcessHostAddressResolver(dirname(__DIR__) . '/Fixtures/stalled-dns-resolver.php'));
    Http::preventStrayRequests();
    Http::fake();
});

it('records a timeout when DNS stalls during an HTTP status check', function (SiteMonitorCheckType $checkType): void {
    $target = SiteMonitorTargetFactory::make($checkType, ['url' => 'https://stalled.example.com/health', 'timeout_ms' => 1000]);
    $startedAt = microtime(true);

    $result = RunSiteMonitorCheckAction::run($target);

    expect($result->state)->toBe(SiteMonitorState::Failing)
        ->and($result->errorType)->toBe('timeout')
        // Hang ceiling only: the stalled resolver answers after 20 seconds.
        ->and(microtime(true) - $startedAt)->toBeLessThan(15.0);
    Http::assertNothingSent();
})->with([
    'HTTP status' => [SiteMonitorCheckType::HttpStatus],
    'SSL certificate' => [SiteMonitorCheckType::SslCertificate],
    'domain expiry' => [SiteMonitorCheckType::DomainExpiry],
]);

it('reports the passed deadline when DNS stalls inside the HTTP client', function (): void {
    $target = SiteMonitorTargetFactory::make(SiteMonitorCheckType::HttpStatus, ['url' => 'https://stalled.example.com/health', 'timeout_ms' => 1000]);

    $result = (new LaravelSiteMonitorHttpClient)->check($target);

    // The HTTP client reports any passed deadline as a connection error.
    expect($result->state)->toBe(SiteMonitorState::Failing)
        ->and($result->errorType)->toBe('connection_error')
        ->and($result->errorMessage)->toContain('deadline was exceeded');
    Http::assertNothingSent();
});
