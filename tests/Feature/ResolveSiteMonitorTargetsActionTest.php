<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\DiscoveryFoundation\Contracts\PublicUrlContributor;
use Capell\DiscoveryFoundation\Data\PublicUrlData;
use Capell\SiteMonitor\Actions\ResolveSiteMonitorTargetsAction;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

it('preserves operator settings and health state when discovered targets are refreshed', function (): void {
    config()->set('capell-site-monitor.site_discovery_auto_targets_enabled', true);
    $entry = new PublicUrlData(
        canonicalUrl: 'https://example.com',
        sourcePackage: 'capell-app/site-discovery',
        site: (new Site)->forceFill(['id' => 10, 'status' => true]),
        language: (new Language)->forceFill(['id' => 1, 'status' => true]),
        title: 'Updated public page',
    );
    app()->instance('site-monitor-test-contributor', new readonly class($entry) implements PublicUrlContributor
    {
        public function __construct(private PublicUrlData $entry) {}

        public function publicUrls(): Collection
        {
            return collect([$this->entry]);
        }
    });
    app()->tag('site-monitor-test-contributor', PublicUrlContributor::TAG);

    $target = createResolvableSiteMonitorTarget([
        'source_package' => 'capell-app/site-discovery',
        'source_key' => hash('sha256', 'https://example.com|10|1'),
        'enabled' => false,
        'current_state' => SiteMonitorState::Failing,
        'interval_minutes' => 20,
        'timeout_ms' => 9000,
        'failure_threshold' => 4,
        'expected_status_minimum' => 204,
        'expected_status_maximum' => 204,
    ]);

    expect(ResolveSiteMonitorTargetsAction::run())->toBeEmpty();
    $target->refresh();

    expect($target->name)->toBe('Updated public page')
        ->and($target->enabled)->toBeFalse()
        ->and($target->current_state)->toBe(SiteMonitorState::Failing)
        ->and($target->interval_minutes)->toBe(20)
        ->and($target->timeout_ms)->toBe(9000)
        ->and($target->failure_threshold)->toBe(4)
        ->and($target->expected_status_minimum)->toBe(204)
        ->and($target->expected_status_maximum)->toBe(204);
});

it('returns enabled targets whose next check is due', function (): void {
    $dueTarget = createResolvableSiteMonitorTarget(['next_check_at' => CarbonImmutable::now()->subMinute()]);

    createResolvableSiteMonitorTarget(['next_check_at' => CarbonImmutable::now()->addMinute()]);
    createResolvableSiteMonitorTarget(['enabled' => false, 'next_check_at' => CarbonImmutable::now()->subMinute()]);

    $targets = ResolveSiteMonitorTargetsAction::run();

    expect($targets)->toHaveCount(1)
        ->and($targets->firstOrFail()->is($dueTarget))->toBeTrue();
});

it('can limit due targets by site and target id', function (): void {
    $matchingTarget = createResolvableSiteMonitorTarget(['site_id' => 10, 'next_check_at' => null]);
    createResolvableSiteMonitorTarget(['site_id' => 20, 'next_check_at' => null]);

    $targets = ResolveSiteMonitorTargetsAction::run(siteId: 10, targetId: $matchingTarget->id);

    expect($targets)->toHaveCount(1)
        ->and($targets->firstOrFail()->is($matchingTarget))->toBeTrue();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createResolvableSiteMonitorTarget(array $overrides = []): SiteMonitorTarget
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
