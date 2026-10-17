<?php

declare(strict_types=1);

use Capell\SiteMonitor\Actions\RunSiteMonitorCheckAction;
use Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient;
use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Capell\SiteMonitor\Support\LaravelSiteMonitorHttpClient;
use Capell\SiteMonitor\Support\RdapDomainExpiryClient;
use Capell\SiteMonitor\Tests\Fixtures\FakeSiteMonitorDomainExpiryClient;
use Capell\SiteMonitor\Tests\Fixtures\FakeSiteMonitorHttpClient;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('disables automatic RDAP redirects so an endpoint cannot bypass the outbound guard', function (): void {
    $redirectPolicy = null;
    Http::preventStrayRequests();
    Http::fake(/** @param array<string, mixed> $options */ function (Request $request, array $options) use (&$redirectPolicy): PromiseInterface {
        $redirectPolicy = $options['allow_redirects'] ?? null;

        return Http::response('', 302, ['Location' => 'http://127.0.0.1/private']);
    });
    config()->set('capell-site-monitor.rdap_endpoints', ['com' => 'https://93.184.216.34/domain/{domain}']);

    expect((new RdapDomainExpiryClient)->expiresAt('example.com'))->toBeNull()
        ->and($redirectPolicy)->toBeFalse();
    Http::assertSentCount(1);
});

it('passes HTTP status checks in the configured status range', function (): void {
    app()->instance(SiteMonitorHttpClient::class, new FakeSiteMonitorHttpClient(new SiteMonitorCheckResultData(
        state: SiteMonitorState::Passing,
        statusCode: 204,
        responseMs: 80,
        expiresAt: null,
        errorType: null,
        errorMessage: null,
    )));

    $result = RunSiteMonitorCheckAction::run(siteMonitorTarget(checkType: SiteMonitorCheckType::HttpStatus));

    expect($result->state)->toBe(SiteMonitorState::Passing)
        ->and($result->statusCode)->toBe(204)
        ->and($result->errorType)->toBeNull();
});

it('fails HTTP status checks outside the expected status range', function (): void {
    app()->instance(SiteMonitorHttpClient::class, new FakeSiteMonitorHttpClient(new SiteMonitorCheckResultData(
        state: SiteMonitorState::Passing,
        statusCode: 500,
        responseMs: 40,
        expiresAt: null,
        errorType: null,
        errorMessage: null,
    )));

    $result = RunSiteMonitorCheckAction::run(siteMonitorTarget(checkType: SiteMonitorCheckType::HttpStatus));

    expect($result->state)->toBe(SiteMonitorState::Failing)
        ->and($result->errorType)->toBe('unexpected_status_code');
});

it('warns when domain expiry is inside the warning window', function (): void {
    app()->instance(SiteMonitorDomainExpiryClient::class, new FakeSiteMonitorDomainExpiryClient(CarbonImmutable::now()->addDays(10)));

    $result = RunSiteMonitorCheckAction::run(siteMonitorTarget(
        checkType: SiteMonitorCheckType::DomainExpiry,
        overrides: ['url' => 'https://example.com'],
    ));

    expect($result->state)->toBe(SiteMonitorState::Warning)
        ->and($result->errorType)->toBe('expires_soon');
});

it('reports unsupported domain expiry suffixes before invoking RDAP clients', function (): void {
    config()->set('capell-site-monitor.rdap_endpoints', [
        'net' => 'https://rdap.verisign.com/net/v1/domain/{domain}',
    ]);

    app()->instance(SiteMonitorDomainExpiryClient::class, new FakeSiteMonitorDomainExpiryClient(CarbonImmutable::now()->addYear()));

    $result = RunSiteMonitorCheckAction::run(siteMonitorTarget(
        checkType: SiteMonitorCheckType::DomainExpiry,
        overrides: ['url' => 'https://example.com'],
    ));

    expect($result->state)->toBe(SiteMonitorState::Failing)
        ->and($result->errorType)->toBe('domain_expiry_tld_unsupported');
});

it('resolves multi-label RDAP suffixes before generic top-level suffixes', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://93.184.216.34/co-uk/example.co.uk' => Http::response([
            'events' => [
                [
                    'eventAction' => 'expiration',
                    'eventDate' => '2027-04-20T12:00:00Z',
                ],
            ],
        ]),
    ]);

    config()->set('capell-site-monitor.rdap_endpoints', [
        'uk' => 'https://93.184.216.34/uk/{domain}',
        'co.uk' => 'https://93.184.216.34/co-uk/{domain}',
    ]);

    $expiresAt = (new RdapDomainExpiryClient)->expiresAt('example.co.uk');

    expect($expiresAt?->toIso8601String())->toBe('2027-04-20T12:00:00+00:00');
});

it('retries temporarily unavailable RDAP lookups', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://93.184.216.34/domain/example.com' => Http::sequence()
            ->push([], 503)
            ->push([
                'events' => [
                    [
                        'eventAction' => 'expiration',
                        'eventDate' => '2027-04-20T12:00:00Z',
                    ],
                ],
            ]),
    ]);

    config()->set('capell-site-monitor.rdap.retry_times', 2);
    config()->set('capell-site-monitor.rdap.retry_delay_ms', 0);
    config()->set('capell-site-monitor.rdap_endpoints', [
        'com' => 'https://93.184.216.34/domain/{domain}',
    ]);

    $expiresAt = (new RdapDomainExpiryClient)->expiresAt('example.com');

    expect($expiresAt?->toIso8601String())->toBe('2027-04-20T12:00:00+00:00');

    Http::assertSentCount(2);
});

it('blocks unsafe HTTP monitor targets before invoking the HTTP client', function (): void {
    app()->instance(SiteMonitorHttpClient::class, new FakeSiteMonitorHttpClient(new SiteMonitorCheckResultData(
        state: SiteMonitorState::Passing,
        statusCode: 200,
        responseMs: 20,
        expiresAt: null,
        errorType: null,
        errorMessage: null,
    )));

    $result = RunSiteMonitorCheckAction::run(siteMonitorTarget(
        checkType: SiteMonitorCheckType::HttpStatus,
        overrides: ['url' => 'http://127.0.0.1/admin'],
    ));

    expect($result->state)->toBe(SiteMonitorState::Failing)
        ->and($result->errorType)->toBe('unsafe_target_address');
});

it('blocks unsafe SSL and domain monitor targets before opening outbound sockets or RDAP clients', function (SiteMonitorCheckType $checkType): void {
    app()->instance(SiteMonitorDomainExpiryClient::class, new FakeSiteMonitorDomainExpiryClient(CarbonImmutable::now()->addYear()));

    $result = RunSiteMonitorCheckAction::run(siteMonitorTarget(
        checkType: $checkType,
        overrides: ['url' => 'http://localhost/internal'],
    ));

    expect($result->state)->toBe(SiteMonitorState::Failing)
        ->and($result->errorType)->toBe('unsafe_target_host');
})->with([
    'ssl' => [SiteMonitorCheckType::SslCertificate],
    'domain' => [SiteMonitorCheckType::DomainExpiry],
]);

it('revalidates redirects before following them', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://93.184.216.34*' => Http::response('', 302, ['Location' => 'http://10.0.0.5/private']),
    ]);

    $result = (new LaravelSiteMonitorHttpClient)->check(siteMonitorTarget(checkType: SiteMonitorCheckType::HttpStatus));

    expect($result->state)->toBe(SiteMonitorState::Failing)
        ->and($result->errorType)->toBe('unsafe_target_address');
});

it('rejects private addresses resolved for HTTP cURL pinning', function (): void {
    $client = new LaravelSiteMonitorHttpClient;

    Http::fake();
    $result = $client->check(siteMonitorTarget(SiteMonitorCheckType::HttpStatus, ['url' => 'https://10.0.0.5/private']));

    expect($result->state)->toBe(SiteMonitorState::Failing)
        ->and($result->errorType)->toBe('unsafe_target_address');
    Http::assertNothingSent();
});

it('rejects private RDAP endpoints without sending an HTTP request', function (): void {
    Http::fake();
    config()->set('capell-site-monitor.rdap_endpoints', ['test' => 'https://10.0.0.5/domain/{domain}']);

    expect((new RdapDomainExpiryClient)->expiresAt('example.test'))->toBeNull();
    Http::assertNothingSent();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function siteMonitorTarget(SiteMonitorCheckType $checkType, array $overrides = []): SiteMonitorTarget
{
    return SiteMonitorTarget::query()->create([
        'name' => 'Example',
        'url' => 'https://93.184.216.34',
        'check_type' => $checkType,
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
