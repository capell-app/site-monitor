<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\FilamentShieldServiceProvider;
use Capell\SiteMonitor\Filament\Pages\SiteMonitorDashboardPage;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorIncidents\SiteMonitorIncidentResource;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\SiteMonitorTargetResource;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Capell\SiteMonitor\Policies\AbstractSiteMonitorResourcePolicy;
use Capell\SiteMonitor\Policies\SiteMonitorIncidentPolicy;
use Capell\SiteMonitor\Policies\SiteMonitorTargetPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    app()->register(FilamentShieldServiceProvider::class);

    Config::set('filament-shield.permissions.separator', ':');
    Config::set('filament-shield.permissions.case', 'pascal');
});

it('exposes the Site Monitor admin page and resources when installed', function (): void {
    expect(SiteMonitorDashboardPage::shouldRegisterNavigation())->toBeTrue()
        ->and(SiteMonitorTargetResource::shouldRegisterNavigation())->toBeTrue()
        ->and(SiteMonitorIncidentResource::shouldRegisterNavigation())->toBeTrue()
        ->and(SiteMonitorTargetResource::getModel())->toBe(SiteMonitorTarget::class)
        ->and(SiteMonitorIncidentResource::getModel())->toBe(SiteMonitorIncident::class);
});

it('denies site monitor resources to ordinary panel users without permissions', function (): void {
    $user = siteMonitorPolicyActor();

    expect((new SiteMonitorTargetPolicy)->viewAny($user))->toBeFalse()
        ->and((new SiteMonitorIncidentPolicy)->viewAny($user))->toBeFalse();
});

it('allows site monitor resource access with matching shield permissions', function (): void {
    $user = siteMonitorPolicyActor([
        siteMonitorPolicyPermission('view_any', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('update', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('view_any', 'SiteMonitorIncident'),
    ]);
    $target = new SiteMonitorTarget(['site_id' => 10]);

    expect((new SiteMonitorTargetPolicy)->viewAny($user))->toBeTrue()
        ->and((new SiteMonitorTargetPolicy)->update($user, $target))->toBeTrue()
        ->and((new SiteMonitorIncidentPolicy)->viewAny($user))->toBeTrue();
});

it('blocks site monitor record access outside assigned sites including incidents', function (): void {
    $user = siteMonitorPolicyActor([
        siteMonitorPolicyPermission('view_any', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('update', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('view_any', 'SiteMonitorIncident'),
    ], assignedSiteIds: collect([10]));
    $target = new SiteMonitorTarget(['site_id' => 20]);
    $incident = new SiteMonitorIncident;
    $incident->setRelation('target', $target);

    expect((new SiteMonitorTargetPolicy)->view($user, $target))->toBeFalse()
        ->and((new SiteMonitorTargetPolicy)->update($user, $target))->toBeFalse()
        ->and((new SiteMonitorIncidentPolicy)->view($user, $incident))->toBeFalse();
});

it('registers policies for site monitor Filament resource models', function (): void {
    expect(Gate::getPolicyFor(SiteMonitorTarget::class))->toBeInstanceOf(SiteMonitorTargetPolicy::class)
        ->and(Gate::getPolicyFor(SiteMonitorIncident::class))->toBeInstanceOf(SiteMonitorIncidentPolicy::class);
});

/**
 * @param  list<string>  $permissions
 */
function siteMonitorPolicyActor(array $permissions = [], ?SupportCollection $assignedSiteIds = null): User
{
    $user = new class extends User
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;

        /** @var list<string> */
        public array $permissions = [];

        /** @var SupportCollection<int, int> */
        public SupportCollection $assignedSiteIds;

        public function checkPermissionTo(string $permission): bool
        {
            return in_array($permission, $this->permissions, true);
        }

        /** @return SupportCollection<int, int> */
        public function getAssignedSiteIds(): SupportCollection
        {
            return $this->assignedSiteIds;
        }
    };

    $user->permissions = $permissions;
    $user->assignedSiteIds = $assignedSiteIds ?? collect([10]);

    return $user;
}

function siteMonitorPolicyPermission(string $ability, string $subject): string
{
    return (new class($ability, $subject) extends AbstractSiteMonitorResourcePolicy
    {
        public function __construct(
            private readonly string $ability,
            private readonly string $policySubject,
        ) {}

        public function permissionName(): string
        {
            return self::permission($this->ability, $this->policySubject);
        }

        protected static function subject(): string
        {
            return 'SiteMonitorTarget';
        }
    })->permissionName();
}
