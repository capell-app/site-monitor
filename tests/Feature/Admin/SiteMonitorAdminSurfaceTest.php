<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\FilamentShieldServiceProvider;
use Capell\Core\Models\Site;
use Capell\SiteMonitor\Actions\AuthoriseSiteMonitorTargetSiteAction;
use Capell\SiteMonitor\Actions\RecordSiteMonitorRunAction;
use Capell\SiteMonitor\Actions\RunSiteMonitorCheckAction;
use Capell\SiteMonitor\Actions\TransitionSiteMonitorIncidentAction;
use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorIncidentStatus;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Events\SiteMonitorIncidentManuallyTransitioned;
use Capell\SiteMonitor\Events\SiteMonitorIncidentResolved;
use Capell\SiteMonitor\Filament\Pages\SiteMonitorDashboardPage;
use Capell\SiteMonitor\Filament\Resources\SiteMonitorIncidents\Pages\EditSiteMonitorIncident;
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
use Capell\SiteMonitor\Tests\Fixtures\FakeSiteMonitorHttpClient;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

it('explains why a paused target cannot run from the real table', function (): void {
    Queue::fake();
    $this->actingAs(siteMonitorPolicyActor([
        siteMonitorPolicyPermission('view_any', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('update', 'SiteMonitorTarget'),
    ]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $target = siteMonitorResourceTarget(10);
    $target->update(['enabled' => false]);

    Livewire::test(ListSiteMonitorTargets::class)
        ->assertActionDisabled(TestAction::make('run_now')->table($target))
        ->assertSee(__('capell-site-monitor::package.actions.enable_before_running'));
    Queue::assertNothingPushed();
});

it('does not let the generic incident form bypass an explicit status transition', function (): void {
    $this->actingAs(siteMonitorPolicyActor([
        siteMonitorPolicyPermission('view_any', 'SiteMonitorIncident'),
        siteMonitorPolicyPermission('update', 'SiteMonitorIncident'),
    ]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $target = siteMonitorResourceTarget(10);
    $incident = SiteMonitorIncident::query()->create([
        'target_id' => $target->id, 'status' => 'open', 'opened_at' => now(),
        'severity' => 'critical', 'summary' => 'Service failure', 'failure_count' => 2,
    ]);

    Livewire::test(EditSiteMonitorIncident::class, ['record' => $incident->id])
        ->fillForm(['status' => 'resolved'])->call('save')->assertHasNoFormErrors();

    expect($incident->refresh()->status)->toBe(SiteMonitorIncidentStatus::Open)
        ->and($incident->resolved_at)->toBeNull();
});

it('rejects contradictory HTTP ranges through the real target forms without saving', function (bool $editing): void {
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
    $component->fillForm([
        'name' => 'Impossible range', 'url' => 'https://93.184.216.34',
        'check_type' => SiteMonitorCheckType::HttpStatus->value, 'site_id' => $site->id,
        'interval_minutes' => 5, 'timeout_ms' => 5000, 'failure_threshold' => 2,
        'expected_status_minimum' => 500, 'expected_status_maximum' => 200,
    ])->call($editing ? 'save' : 'create')->assertHasFormErrors(['expected_status_maximum']);

    expect(SiteMonitorTarget::query()->count())->toBe($editing ? 1 : 0);
    if ($target instanceof SiteMonitorTarget) {
        expect($target->refresh()->expected_status_minimum)->toBe(200)
            ->and($target->expected_status_maximum)->toBe(399);
    }
})->with(['create' => [false], 'edit' => [true]]);

it('records manual resolve and reopen decisions through the real incident actions', function (): void {
    Event::fake([SiteMonitorIncidentManuallyTransitioned::class, SiteMonitorIncidentResolved::class]);
    $this->travelTo(now()->startOfSecond());
    $this->actingAs(siteMonitorPolicyActor([
        siteMonitorPolicyPermission('view_any', 'SiteMonitorIncident'),
        siteMonitorPolicyPermission('update', 'SiteMonitorIncident'),
    ]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $target = siteMonitorResourceTarget(10);
    $target->update(['failure_threshold' => 1]);

    $failure = new SiteMonitorCheckResultData(SiteMonitorState::Failing, 503, 20, null, 'unexpected_status_code', 'HTTP 503');
    RecordSiteMonitorRunAction::run($target, $failure);
    $incident = $target->openIncident()->firstOrFail();
    $runId = $incident->latest_run_id;
    $page = Livewire::test(EditSiteMonitorIncident::class, ['record' => $incident->id]);
    $page->callAction('resolve', ['reason' => 'Accepted maintenance'])->assertHasNoActionErrors();

    expect($incident->refresh()->status)->toBe(SiteMonitorIncidentStatus::Resolved)
        ->and($incident->resolved_at?->equalTo(now()))->toBeTrue()
        ->and($incident->latest_run_id)->toBe($runId)
        ->and($target->refresh()->current_state)->toBe(SiteMonitorState::Failing)
        ->and(data_get($incident->metadata, 'operator_transitions.0.actorId'))->toBe('1')
        ->and(data_get($incident->metadata, 'operator_transitions.0.reason'))->toBe('Accepted maintenance');
    TransitionSiteMonitorIncidentAction::run($incident, SiteMonitorIncidentStatus::Resolved, 'Repeated request');
    Event::assertDispatchedTimes(SiteMonitorIncidentManuallyTransitioned::class, 1);
    Event::assertDispatched(SiteMonitorIncidentManuallyTransitioned::class, fn (SiteMonitorIncidentManuallyTransitioned $event): bool => $event->transition->fromStatus === SiteMonitorIncidentStatus::Open
        && $event->transition->toStatus === SiteMonitorIncidentStatus::Resolved
        && $event->transition->actorId === '1'
        && $event->transition->reason === 'Accepted maintenance'
        && $event->transition->occurredAt->equalTo(now()));
    Event::assertNotDispatched(SiteMonitorIncidentResolved::class);

    $page->callAction('reopen', ['reason' => 'Maintenance ended'])->assertHasNoActionErrors();
    expect($incident->refresh()->status)->toBe(SiteMonitorIncidentStatus::Open)
        ->and($incident->resolved_at)->toBeNull()
        ->and(data_get($incident->metadata, 'operator_transitions'))->toHaveCount(2);
    RecordSiteMonitorRunAction::run($target->refresh(), $failure);
    expect(data_get($incident->refresh()->metadata, 'operator_transitions'))->toHaveCount(2);
    RecordSiteMonitorRunAction::run($target->refresh(), new SiteMonitorCheckResultData(SiteMonitorState::Passing, 200, 20, null, null, null));
    expect($incident->refresh()->status)->toBe(SiteMonitorIncidentStatus::Resolved)
        ->and($incident->resolved_at)->not->toBeNull();
    Event::assertDispatchedTimes(SiteMonitorIncidentManuallyTransitioned::class, 2);
    Event::assertDispatched(SiteMonitorIncidentResolved::class, fn (SiteMonitorIncidentResolved $event): bool => $event->run->state === SiteMonitorState::Passing);
});

it('starts a new incident on a failing probe after manual closure and prevents a second open incident', function (): void {
    $this->actingAs(siteMonitorPolicyActor([siteMonitorPolicyPermission('update', 'SiteMonitorIncident')]));
    $target = siteMonitorResourceTarget(10);
    $target->update(['failure_threshold' => 1]);

    $failure = new SiteMonitorCheckResultData(SiteMonitorState::Failing, 503, 20, null, null, null);
    RecordSiteMonitorRunAction::run($target, $failure);
    $original = $target->openIncident()->firstOrFail();
    TransitionSiteMonitorIncidentAction::run($original, SiteMonitorIncidentStatus::Resolved, 'Operator closure');
    RecordSiteMonitorRunAction::run($target->refresh(), $failure);

    expect($target->incidents()->count())->toBe(2)
        ->and($target->openIncident()->firstOrFail()->is($original))->toBeFalse();
    expect(fn (): mixed => TransitionSiteMonitorIncidentAction::run($original, SiteMonitorIncidentStatus::Open, 'Reopen old incident'))->toThrow(ValidationException::class);
    expect($original->refresh()->status)->toBe(SiteMonitorIncidentStatus::Resolved);
});

it('denies manual transitions for view only or foreign actors without changing evidence', function (bool $foreign): void {
    Event::fake([SiteMonitorIncidentManuallyTransitioned::class]);
    $this->actingAs(siteMonitorPolicyActor([
        siteMonitorPolicyPermission('view_any', 'SiteMonitorIncident'),
        ...($foreign ? [siteMonitorPolicyPermission('update', 'SiteMonitorIncident')] : []),
    ]));
    $target = siteMonitorResourceTarget($foreign ? 20 : 10);
    $incident = SiteMonitorIncident::query()->create([
        'target_id' => $target->id, 'status' => 'open', 'opened_at' => now(),
        'severity' => 'critical', 'summary' => 'Unavailable', 'failure_count' => 1,
    ]);
    expect(fn (): mixed => TransitionSiteMonitorIncidentAction::run($incident, SiteMonitorIncidentStatus::Resolved, 'Forged request'))->toThrow(AuthorizationException::class);
    expect($incident->refresh()->status)->toBe(SiteMonitorIncidentStatus::Open)
        ->and($incident->resolved_at)->toBeNull();
    Event::assertNotDispatched(SiteMonitorIncidentManuallyTransitioned::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    if (! $foreign) {
        Livewire::test(EditSiteMonitorIncident::class, ['record' => $incident->id])->assertForbidden();
    }
})->with(['view only' => [false], 'foreign site' => [true]]);

it('saves valid HTTP ranges through the forms and classifies their real fake HTTP results', function (bool $editing, int $maximum): void {
    $site = Site::factory()->create();
    $this->actingAs(siteMonitorPolicyActor([
        siteMonitorPolicyPermission('view_any', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('create', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('update', 'SiteMonitorTarget'),
    ], collect([(int) $site->id])));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $target = $editing ? siteMonitorResourceTarget((int) $site->id) : null;
    $component = $editing
        ? Livewire::test(EditSiteMonitorTarget::class, ['record' => $target?->id])
        : Livewire::test(CreateSiteMonitorTarget::class);
    $component->fillForm([
        'name' => 'Valid range', 'url' => 'https://93.184.216.34', 'site_id' => $site->id,
        'check_type' => SiteMonitorCheckType::HttpStatus->value,
        'expected_status_minimum' => 200, 'expected_status_maximum' => $maximum,
    ])->call($editing ? 'save' : 'create')->assertHasNoFormErrors();
    $target = SiteMonitorTarget::query()->sole();
    foreach ([200, $maximum, $maximum + 1, 199] as $status) {
        app()->instance(SiteMonitorHttpClient::class, new FakeSiteMonitorHttpClient(new SiteMonitorCheckResultData(SiteMonitorState::Passing, $status, 20, null, null, null)));
        expect(RunSiteMonitorCheckAction::run($target)->state)->toBe($status >= 200 && $status <= $maximum ? SiteMonitorState::Passing : SiteMonitorState::Failing);
    }
})->with(['create equal' => [false, 200], 'create range' => [false, 399], 'edit equal' => [true, 200], 'edit range' => [true, 399]]);

it('enforces status ranges for model writers and exposes legacy repair guidance without rewriting values', function (): void {
    expect(fn (): SiteMonitorTarget => SiteMonitorTarget::query()->create([
        'name' => 'Invalid non-admin target', 'url' => 'https://93.184.216.34',
        'check_type' => SiteMonitorCheckType::HttpStatus,
        'expected_status_minimum' => 500, 'expected_status_maximum' => 200,
    ]))->toThrow(ValidationException::class);
    expect(SiteMonitorTarget::query()->count())->toBe(0);

    $target = siteMonitorResourceTarget(10);
    expect(fn () => $target->update(['expected_status_minimum' => 500, 'expected_status_maximum' => 200]))->toThrow(ValidationException::class);
    expect($target->refresh()->expected_status_minimum)->toBe(200);
    DB::table('site_monitor_targets')->where('id', $target->id)->update(['expected_status_minimum' => 500, 'expected_status_maximum' => 200]);
    $this->actingAs(siteMonitorPolicyActor([
        siteMonitorPolicyPermission('view_any', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('update', 'SiteMonitorTarget'),
    ]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ListSiteMonitorTargets::class)->assertSee(__('capell-site-monitor::package.validation.repair_status_range'));
    expect($target->refresh()->expected_status_minimum)->toBe(500);
});

it('gives honest feedback when a mounted run action becomes stale before submission', function (): void {
    Queue::fake();
    $this->actingAs(siteMonitorPolicyActor([
        siteMonitorPolicyPermission('view_any', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('update', 'SiteMonitorTarget'),
    ]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $target = siteMonitorResourceTarget(10);
    $component = Livewire::test(ListSiteMonitorTargets::class);
    $instance = $component->instance();
    throw_unless($instance instanceof ListSiteMonitorTargets, RuntimeException::class, 'Target table component missing.');
    $action = $instance->getTable()->getAction('run_now');
    throw_unless($action instanceof Action, RuntimeException::class, 'Run now action missing.');
    $action->record($target);
    SiteMonitorTarget::query()->whereKey($target->id)->update(['enabled' => false]);
    $action->call();
    Notification::assertNotified(
        Notification::make()->title(__('capell-site-monitor::package.actions.enable_before_running'))->warning(),
    );
    Queue::assertNothingPushed();
});

it('shows stale observation guidance beside the retained passing result in the real target table', function (): void {
    $this->travelTo(now()->startOfSecond());
    $this->actingAs(siteMonitorPolicyActor([siteMonitorPolicyPermission('view_any', 'SiteMonitorTarget')]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $target = siteMonitorResourceTarget(10);
    $target->update(['current_state' => SiteMonitorState::Passing, 'last_checked_at' => now()->subMinutes(11)]);
    Livewire::test(ListSiteMonitorTargets::class)
        ->assertTableColumnStateSet('evidence', __('capell-site-monitor::package.evidence.overdue'), $target)
        ->assertSee(__('capell-site-monitor::package.evidence.guidance'))
        ->assertSee(__('capell-site-monitor::package.fields.current_state'));
    expect($target->refresh()->current_state)->toBe(SiteMonitorState::Passing);
});

it('retains HTTP bounds when switching to an expiry check and back through the real form', function (): void {
    $site = Site::factory()->create();
    $this->actingAs(siteMonitorPolicyActor([
        siteMonitorPolicyPermission('view_any', 'SiteMonitorTarget'),
        siteMonitorPolicyPermission('update', 'SiteMonitorTarget'),
    ], collect([(int) $site->id])));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $target = siteMonitorResourceTarget((int) $site->id);
    $target->update(['expected_status_minimum' => 204, 'expected_status_maximum' => 204]);
    Livewire::test(EditSiteMonitorTarget::class, ['record' => $target->id])
        ->fillForm(['check_type' => SiteMonitorCheckType::SslCertificate->value])
        ->call('save')->assertHasNoFormErrors();
    expect($target->refresh()->expected_status_minimum)->toBe(204)
        ->and($target->expected_status_maximum)->toBe(204);
    Livewire::test(EditSiteMonitorTarget::class, ['record' => $target->id])
        ->fillForm(['check_type' => SiteMonitorCheckType::HttpStatus->value])
        ->call('save')->assertHasNoFormErrors();
    expect($target->refresh()->expected_status_minimum)->toBe(204)
        ->and($target->expected_status_maximum)->toBe(204);
});

it('renders dashboard observation guidance and enabled evidence counters for an owner', function (): void {
    $actor = new class extends User
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;

        public function isGlobalAdmin(): bool
        {
            return true;
        }
    };
    $this->actingAs($actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $target = siteMonitorResourceTarget(10);
    $target->update(['last_checked_at' => now()->subMinutes(11), 'current_state' => SiteMonitorState::Passing]);

    $component = Livewire::test(SiteMonitorDashboardPage::class)
        ->assertSuccessful()
        ->assertSeeHtml('data-site-monitor-evidence-guidance')
        ->assertSee(__('capell-site-monitor::package.evidence.policy'))
        ->assertSee(__('capell-site-monitor::package.evidence.guidance'))
        ->assertSee(__('capell-site-monitor::package.dashboard.overdue_targets'));
    $instance = $component->instance();
    throw_unless($instance instanceof SiteMonitorDashboardPage, RuntimeException::class, 'Dashboard component missing.');
    expect($instance->dashboard()->overdueTargets)->toBe(1)
        ->and($instance->dashboard()->neverCheckedTargets)->toBe(0);
});
