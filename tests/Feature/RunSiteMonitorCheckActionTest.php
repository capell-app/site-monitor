<?php

declare(strict_types=1);

use Capell\SiteMonitor\Actions\RunSiteMonitorCheckAction;
use Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient;
use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Capell\SiteMonitor\Tests\Fixtures\FakeSiteMonitorDomainExpiryClient;
use Capell\SiteMonitor\Tests\Fixtures\FakeSiteMonitorHttpClient;
use Carbon\CarbonImmutable;

it('passes HTTP status checks in the configured status range', function (): void {
    app()->instance(SiteMonitorHttpClient::class, new FakeSiteMonitorHttpClient(new SiteMonitorCheckResultData(
        state: SiteMonitorState::Passing,
        statusCode: 204,
        responseMs: 80,
        expiresAt: null,
        errorType: null,
        errorMessage: null,
    )));

    $result = (new RunSiteMonitorCheckAction)->handle(siteMonitorTarget(checkType: SiteMonitorCheckType::HttpStatus));

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

    $result = (new RunSiteMonitorCheckAction)->handle(siteMonitorTarget(checkType: SiteMonitorCheckType::HttpStatus));

    expect($result->state)->toBe(SiteMonitorState::Failing)
        ->and($result->errorType)->toBe('unexpected_status_code');
});

it('warns when domain expiry is inside the warning window', function (): void {
    app()->instance(SiteMonitorDomainExpiryClient::class, new FakeSiteMonitorDomainExpiryClient(CarbonImmutable::now()->addDays(10)));

    $result = (new RunSiteMonitorCheckAction)->handle(siteMonitorTarget(checkType: SiteMonitorCheckType::DomainExpiry));

    expect($result->state)->toBe(SiteMonitorState::Warning)
        ->and($result->errorType)->toBe('expires_soon');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function siteMonitorTarget(SiteMonitorCheckType $checkType, array $overrides = []): SiteMonitorTarget
{
    return SiteMonitorTarget::query()->create([
        'name' => 'Example',
        'url' => 'https://example.com',
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
