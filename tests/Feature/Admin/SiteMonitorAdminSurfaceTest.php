<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\FilamentShieldServiceProvider;
use Capell\Core\Models\Site;
use Capell\SiteMonitor\Actions\AuthoriseSiteMonitorTargetSiteAction;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Filament\Pages\SiteMonitorDashboardPage;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorIncidents\SiteMonitorIncidentResource;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\Pages\CreateSiteMonitorTarget;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\Pages\EditSiteMonitorTarget;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\Pages\ListSiteMonitorTargets;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorTargets\SiteMonitorTargetResource;
use Capell\SiteMonitor\Jobs\RunSiteMonitorTargetJob;
use Capell\SiteMonitor\Models\SiteMonitorIncident;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Capell\SiteMonitor\Policies\AbstractSiteMonitorResourcePolicy;
use Capell\SiteMonitor\Policies\SiteMonitorIncidentPolicy;
use Capell\SiteMonitor\Policies\SiteMonitorTargetPolicy;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    app()->register(FilamentShieldServiceProvider::class);

    Config::set('filament-shield.permissions.separator', ':');
    Config::set('filament-shield.permissions.case', 'pascal');
});

it('exposes the Site Monitor admin page and resources when installed', function (): void {
    $globalActor = new class extends User
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;

        public function isGlobalAdmin(): bool
        {
            return true;
        }
    };
    $this->actingAs($globalActor);

    expect(SiteMonitorDashboardPage::shouldRegisterNavigation())->toBeTrue()
        ->and(SiteMonitorTargetResource::shouldRegisterNavigation())->toBeTrue()
        ->and(SiteMonitorIncidentResource::shouldRegisterNavigation())->toBeTrue()
        ->and(SiteMonitorTargetResource::getModel())->toBe(SiteMonitorTarget::class)
        ->and(SiteMonitorIncidentResource::getModel())->toBe(SiteMonitorIncident::class);
});

it('hides the portfolio-wide dashboard from scoped actors', function (): void {
    $this->actingAs(siteMonitorPolicyActor([siteMonitorPolicyPermission('view_any', 'SiteMonitorTarget')]));

    expect(SiteMonitorDashboardPage::canAccess())->toBeFalse();
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

it('limits target and incident listings to the operators assigned sites', function (): void {
    $this->actingAs(siteMonitorPolicyActor([
        siteMonitorPolicyPermission('view_any', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('view_any', 'SiteMonitorIncident'),
    ]));
    $own = siteMonitorResourceTarget(10);
    $foreign = siteMonitorResourceTarget(20);
    $global = siteMonitorResourceTarget(null);
    $incidentDefaults = ['status' => 'open', 'opened_at' => now()];
    $ownIncident = SiteMonitorIncident::query()->create([...$incidentDefaults, 'target_id' => $own->id, 'summary' => 'Assigned-site failure']);
    SiteMonitorIncident::query()->create([...$incidentDefaults, 'target_id' => $foreign->id, 'summary' => 'Foreign-site failure']);
    SiteMonitorIncident::query()->create([...$incidentDefaults, 'target_id' => $global->id, 'summary' => 'Global failure']);

    expect(SiteMonitorTargetResource::getEloquentQuery()->pluck('id')->all())->toBe([$own->id])
        ->and(SiteMonitorIncidentResource::getEloquentQuery()->pluck('id')->all())->toBe([$ownIncident->id]);
});

it('denies scoped access to unowned targets and orphan incidents', function (): void {
    $actor = siteMonitorPolicyActor([
        siteMonitorPolicyPermission('view_any', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('update', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('view_any', 'SiteMonitorIncident'),
        siteMonitorPolicyPermission('update', 'SiteMonitorIncident'),
    ]);
    $target = new SiteMonitorTarget;
    $incident = new SiteMonitorIncident;
    $incident->setRelation('target', null);

    expect((new SiteMonitorTargetPolicy)->view($actor, $target))->toBeFalse()
        ->and((new SiteMonitorTargetPolicy)->update($actor, $target))->toBeFalse()
        ->and((new SiteMonitorIncidentPolicy)->view($actor, $incident))->toBeFalse()
        ->and((new SiteMonitorIncidentPolicy)->update($actor, $incident))->toBeFalse();
});

function siteMonitorResourceTarget(?int $siteId): SiteMonitorTarget
{
    return SiteMonitorTarget::query()->create([
        'name' => 'Monitored service',
        'url' => 'https://example.com',
        'site_id' => $siteId,
        'check_type' => SiteMonitorCheckType::HttpStatus,
        'interval_minutes' => 5,
        'timeout_ms' => 5000,
        'failure_threshold' => 2,
        'expected_status_minimum' => 200,
        'expected_status_maximum' => 399,
        'enabled' => true,
        'current_state' => SiteMonitorState::Unknown,
    ]);
}

it('rejects foreign and null submitted target ownership before saving', function (?int $siteId): void {
    $this->actingAs(siteMonitorPolicyActor([siteMonitorPolicyPermission('create', 'SiteMonitorTarget')]));

    expect(function () use ($siteId): void {
        AuthoriseSiteMonitorTargetSiteAction::run($siteId);
    })->toThrow(HttpException::class);
})->with(['foreign site' => [20], 'missing ownership' => [null]]);

it('accepts an existing assigned site but refuses a nonexistent assigned site', function (): void {
    $site = Site::factory()->create();
    $this->actingAs(siteMonitorPolicyActor([], collect([(int) $site->getKey(), 999999])));

    AuthoriseSiteMonitorTargetSiteAction::run((int) $site->getKey());
    expect(function (): void {
        AuthoriseSiteMonitorTargetSiteAction::run(999999);
    })->toThrow(ValidationException::class);
});

it('only lets update authorised operators queue a target check from the real table', function (bool $canUpdate): void {
    Queue::fake();
    $permissions = [siteMonitorPolicyPermission('view_any', 'SiteMonitorTarget')];
    if ($canUpdate) {
        $permissions[] = siteMonitorPolicyPermission('update', 'SiteMonitorTarget');
    }

    $this->actingAs(siteMonitorPolicyActor($permissions));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $target = siteMonitorResourceTarget(10);
    $component = Livewire::test(ListSiteMonitorTargets::class)
        ->assertCanSeeTableRecords([$target]);
    $action = TestAction::make('run_now')->table($target);
    if ($canUpdate) {
        $component->assertActionVisible($action)->callAction($action);
        Queue::assertPushed(RunSiteMonitorTargetJob::class, fn (RunSiteMonitorTargetJob $job): bool => $job->targetId === $target->id);
    } else {
        $component->assertActionHidden($action);
        Queue::assertNothingPushed();
    }
})->with(['view only' => [false], 'operator' => [true]]);

it('refuses forged run actions for foreign, unowned and view only targets', function (string $assignment): void {
    Queue::fake();
    $permissions = [siteMonitorPolicyPermission('view_any', 'SiteMonitorTarget')];
    if ($assignment !== 'view-only') {
        $permissions[] = siteMonitorPolicyPermission('update', 'SiteMonitorTarget');
    }

    $this->actingAs(siteMonitorPolicyActor($permissions));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $target = siteMonitorResourceTarget(match ($assignment) {
        'foreign' => 20,
        'unowned' => null,
        default => 10,
    });

    Livewire::test(ListSiteMonitorTargets::class)
        ->call('mountAction', 'run_now', [], ['table' => true, 'recordKey' => (string) $target->id])
        ->call('callMountedAction');

    Queue::assertNothingPushed();
})->with(['foreign', 'unowned', 'view-only']);

it('saves assigned ownership through the real create and edit forms', function (bool $editing): void {
    $site = Site::factory()->create();
    $this->actingAs(siteMonitorPolicyActor([
        siteMonitorPolicyPermission('view_any', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('create', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('update', 'SiteMonitorTarget'),
    ], collect([(int) $site->getKey()])));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $target = $editing ? siteMonitorResourceTarget((int) $site->getKey()) : null;
    $component = $editing
        ? Livewire::test(EditSiteMonitorTarget::class, ['record' => $target?->id])
        : Livewire::test(CreateSiteMonitorTarget::class);

    $component->assertSuccessful()->fillForm([
        'name' => 'Assigned service',
        'url' => 'https://example.com',
        'check_type' => SiteMonitorCheckType::HttpStatus->value,
        'site_id' => $site->getKey(),
        'interval_minutes' => 5,
        'timeout_ms' => 5000,
        'failure_threshold' => 2,
        'expected_status_minimum' => 200,
        'expected_status_maximum' => 399,
    ])->call($editing ? 'save' : 'create')->assertHasNoFormErrors();

    expect(SiteMonitorTarget::query()->count())->toBe(1)
        ->and(SiteMonitorTarget::query()->sole()->site_id)->toBe((int) $site->getKey())
        ->and(SiteMonitorTarget::query()->sole()->name)->toBe('Assigned service');
})->with(['create' => [false], 'edit' => [true]]);

it('rejects inaccessible ownership through the real create and edit forms', function (bool $editing, string $assignment): void {
    $site = Site::factory()->create();
    $foreign = Site::factory()->create();
    $this->actingAs(siteMonitorPolicyActor([
        siteMonitorPolicyPermission('view_any', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('create', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('update', 'SiteMonitorTarget'),
    ], collect([(int) $site->getKey()])));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $target = $editing ? siteMonitorResourceTarget((int) $site->getKey()) : null;
    $component = $editing
        ? Livewire::test(EditSiteMonitorTarget::class, ['record' => $target?->id])
        : Livewire::test(CreateSiteMonitorTarget::class);
    $siteId = match ($assignment) {
        'foreign' => $foreign->getKey(),
        'missing' => 999999,
        default => null,
    };
    $component->assertSuccessful();
    $component->fillForm([
        'name' => 'Scoped service',
        'url' => 'https://example.com',
        'check_type' => SiteMonitorCheckType::HttpStatus->value,
        'site_id' => $siteId,
        'interval_minutes' => 5,
        'timeout_ms' => 5000,
        'failure_threshold' => 2,
        'expected_status_minimum' => 200,
        'expected_status_maximum' => 399,
    ])->call($editing ? 'save' : 'create')->assertHasFormErrors(['site_id']);

    expect(SiteMonitorTarget::query()->count())->toBe($editing ? 1 : 0);
    if ($target instanceof SiteMonitorTarget) {
        expect($target->refresh()->site_id)->toBe((int) $site->getKey());
    }
})->with([
    'create foreign' => [false, 'foreign'],
    'create missing' => [false, 'missing'],
    'create unowned' => [false, 'null'],
    'edit foreign' => [true, 'foreign'],
    'edit missing' => [true, 'missing'],
    'edit unowned' => [true, 'null'],
]);

/**
 * @param  list<string>  $permissions
 * @param  SupportCollection<int, int>|null  $assignedSiteIds
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

        public function hasRole(string $role): bool
        {
            return false;
        }

        /** @return SupportCollection<int, int> */
        public function getAssignedSiteIds(): SupportCollection
        {
            return $this->assignedSiteIds;
        }
    };

    $user->permissions = $permissions;
    $user->forceFill(['id' => 1, 'name' => 'Scoped operator', 'email' => 'scoped@example.test']);
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
