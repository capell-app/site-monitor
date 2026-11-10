<?php

declare(strict_types=1);

use Capell\Core\Contracts\Security\SecurityInventoryContributor;
use Capell\Core\Contracts\Security\SecurityWeaknessCheck;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Capell\SiteMonitor\Security\CertificateExpiryWeaknessCheck;
use Capell\SiteMonitor\Security\SiteMonitorSecurityInventoryContributor;
use Carbon\CarbonImmutable;

it('registers the package security check and contributor', function (): void {
    expect(resolve(SecurityWeaknessRegistry::class)->isValid('site_monitor.certificate_expiry'))->toBeTrue()
        ->and(collect(app()->tagged(SecurityWeaknessCheck::TAG))->contains(fn (object $check): bool => $check instanceof CertificateExpiryWeaknessCheck))->toBeTrue()
        ->and(collect(app()->tagged(SecurityInventoryContributor::TAG))->contains(fn (object $contributor): bool => $contributor instanceof SiteMonitorSecurityInventoryContributor))->toBeTrue();
});

it('reports expired, approaching and stale certificates with stable target fingerprints', function (): void {
    $check = new CertificateExpiryWeaknessCheck;
    $inventory = fn (array $flags): SecurityPostureInventoryData => new SecurityPostureInventoryData(null, [], $flags, [], CarbonImmutable::now());
    $flags = ['site_monitor.target_count' => 1, 'site_monitor.target_0_id' => 42, 'site_monitor.target_0_observation' => 'fresh', 'site_monitor.target_0_expired' => true, 'site_monitor.target_0_approaching' => true, 'site_monitor.warning_days' => 30];
    $expired = $check->detect($inventory($flags))[0];
    $flags['site_monitor.target_0_expired'] = false;
    $approaching = $check->detect($inventory($flags))[0];
    $flags['site_monitor.target_0_observation'] = 'stale';
    $stale = $check->detect($inventory($flags))[0];
    expect($expired->severity)->toBe(SecurityWeaknessSeverity::High)
        ->and($approaching->severity)->toBe(SecurityWeaknessSeverity::Medium)
        ->and($stale->severity)->toBe(SecurityWeaknessSeverity::Medium)
        ->and($stale->evidence['observation'])->toBe('stale')
        ->and($stale->fingerprint)->toBe($expired->fingerprint)
        ->and($stale->remediable)->toBeFalse()
        ->and($check->detect($inventory([]))[0]->evidence['observation'])->toBe('missing');
    $flags['site_monitor.target_0_observation'] = 'fresh';
    $flags['site_monitor.target_0_approaching'] = false;
    expect($check->detect($inventory($flags)))->toBe([]);
});

it('collects certificate observations without returning target URLs', function (): void {
    $now = CarbonImmutable::parse('2026-11-03 12:00:00 UTC');
    CarbonImmutable::setTestNow($now);
    try {
        $target = SiteMonitorTarget::query()->create(['name' => 'Example', 'url' => 'https://user:secret@example.test', 'check_type' => SiteMonitorCheckType::SslCertificate, 'interval_minutes' => 5, 'timeout_ms' => 5000, 'failure_threshold' => 2, 'expected_status_minimum' => 200, 'expected_status_maximum' => 399, 'enabled' => true, 'current_state' => SiteMonitorState::Passing]);
        SiteMonitorRun::query()->create(['target_id' => $target->id, 'state' => SiteMonitorState::Passing, 'checked_at' => $now, 'expires_at' => $now->addDays(3)]);
        $contributor = new SiteMonitorSecurityInventoryContributor;
        $flags = $contributor->flags();
        expect($contributor->prefix())->toBe('site_monitor')
            ->and($flags['target_count'])->toBe(1)
            ->and($flags['target_0_id'])->toBe($target->id)
            ->and($flags['target_0_observation'])->toBe('fresh')
            ->and($flags['target_0_approaching'])->toBeTrue()
            ->and(json_encode($flags))->not->toContain('secret', 'example.test');
    } finally {
        CarbonImmutable::setTestNow();
    }
});
