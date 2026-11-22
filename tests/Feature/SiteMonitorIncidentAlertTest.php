<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/SiteMonitorAlertQueue.php';

use Capell\Core\Contracts\Security\ResolvesHostAddresses;
use Capell\SiteMonitor\Actions\RecordSiteMonitorRunAction;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Exceptions\SiteMonitorHttpAlertDeliveryException;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Capell\SiteMonitor\Notifications\Channels\SiteMonitorSlackChannel;
use Capell\SiteMonitor\Notifications\Channels\SiteMonitorWebhookChannel;
use Capell\SiteMonitor\Notifications\SiteMonitorIncidentNotification;
use Capell\SiteMonitor\Support\SiteMonitorAlertHttpFactory;
use Composer\Semver\Semver;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Message\RequestInterface;

beforeEach(function (): void {
    useSiteMonitorAlertQueue();
});

function alertTarget(string $name): SiteMonitorTarget
{
    return SiteMonitorTarget::query()->create([
        'name' => $name,
        'url' => 'https://' . strtolower($name) . '.example.com',
        'check_type' => SiteMonitorCheckType::HttpStatus,
        'interval_minutes' => 5,
        'timeout_ms' => 5000,
        'failure_threshold' => 1,
        'expected_status_minimum' => 200,
        'expected_status_maximum' => 399,
        'enabled' => true,
        'current_state' => SiteMonitorState::Unknown,
    ]);
}

function alertResult(SiteMonitorState $state): SiteMonitorCheckResultData
{
    return new SiteMonitorCheckResultData(
        state: $state,
        statusCode: $state === SiteMonitorState::Passing ? 200 : 503,
        responseMs: 100,
        expiresAt: null,
        errorType: $state === SiteMonitorState::Passing ? null : 'http_status',
        errorMessage: null,
    );
}

it('emails the configured addresses about the failing site when an incident opens, then on recovery', function (): void {
    Notification::fake();
    config()->set('capell-site-monitor.alerts.mail_to', 'ops@example.com, not-an-address, second@example.com');

    $target = alertTarget('Shopfront');
    $target->forceFill(['url' => 'https://shopfront.example.com/private-mail-path?secret=mail-query'])->save();
    RecordSiteMonitorRunAction::run($target, alertResult(SiteMonitorState::Failing));
    workSiteMonitorAlertQueue();

    Notification::assertSentOnDemand(SiteMonitorIncidentNotification::class, function (SiteMonitorIncidentNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
        $mail = $notification->toMail($notifiable);

        return $notifiable->routes['mail'] === ['ops@example.com', 'second@example.com']
            && str_contains($mail->subject, 'Shopfront')
            && str_contains(implode(' ', $mail->introLines), 'https://shopfront.example.com')
            && ! str_contains(implode(' ', $mail->introLines), 'private-mail-path')
            && ! str_contains(implode(' ', $mail->introLines), 'mail-query');
    });

    RecordSiteMonitorRunAction::run($target->refresh(), alertResult(SiteMonitorState::Passing));
    workSiteMonitorAlertQueue();

    Notification::assertSentOnDemandTimes(SiteMonitorIncidentNotification::class, 2);
});

it('sends nothing without addresses and skips recovery mail when switched off', function (): void {
    Notification::fake();
    config()->set('capell-site-monitor.alerts.mail_to', false);

    $target = alertTarget('Quiet');
    RecordSiteMonitorRunAction::run($target, alertResult(SiteMonitorState::Failing));
    workSiteMonitorAlertQueue();
    Notification::assertNothingSent();

    config()->set('capell-site-monitor.alerts.mail_to', 'ops@example.com');
    config()->set('capell-site-monitor.alerts.notify_on_recovery', false);
    $other = alertTarget('Other');
    RecordSiteMonitorRunAction::run($other, alertResult(SiteMonitorState::Failing));
    RecordSiteMonitorRunAction::run($other->refresh(), alertResult(SiteMonitorState::Passing));
    workSiteMonitorAlertQueue();

    Notification::assertSentOnDemandTimes(SiteMonitorIncidentNotification::class, 1);
});

function fakeAlertDns(): void
{
    app()->instance(ResolvesHostAddresses::class, new class implements ResolvesHostAddresses
    {
        #[Override]
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
}

function fakeSiteMonitorAlertHttp(): Factory
{
    $factory = app(SiteMonitorAlertHttpFactory::class)->client;
    $factory->preventStrayRequests();

    return $factory;
}

it('posts opening and recovery alerts without mail and does not repeat an ongoing outage', function (string $channel): void {
    fakeAlertDns();
    $http = fakeSiteMonitorAlertHttp()->fake();
    config()->set('capell-site-monitor.alerts.mail_to', '');
    config()->set('capell-site-monitor.alerts.' . $channel . '_url', 'https://alerts.example.com/' . $channel);
    config()->set('capell-site-monitor.alerts.webhook_token', 'receiver-secret');

    $target = alertTarget('Delivery');
    $target->forceFill(['url' => 'https://user:password@delivery.example.com:8443/private-path-token?token=target-secret#private'])->save();
    $run = RecordSiteMonitorRunAction::run($target, alertResult(SiteMonitorState::Failing));
    RecordSiteMonitorRunAction::run($target->refresh(), alertResult(SiteMonitorState::Failing));
    RecordSiteMonitorRunAction::run($target->refresh(), alertResult(SiteMonitorState::Passing));
    workSiteMonitorAlertQueue();

    $http->assertSentCount(2);
    $requests = $http->recorded()->map(static fn (array $pair): Request => $pair[0]);
    $opened = $requests->get(0) ?? throw new RuntimeException('Opening alert was not sent.');
    $recovered = $requests->get(1) ?? throw new RuntimeException('Recovery alert was not sent.');
    expect($opened->method())->toBe('POST')
        ->and($opened->url())->toBe('https://alerts.example.com/' . $channel)
        ->and($opened->hasHeader('Content-Type', 'application/json'))->toBeTrue();
    foreach (['password', 'private-path-token', 'target-secret', '#private', 'receiver-secret'] as $secret) {
        expect($opened->body())->not->toContain($secret);
        expect($recovered->body())->not->toContain($secret);
    }

    if ($channel === 'slack') {
        expect($opened['text'])->toContain('Delivery', 'https://delivery.example.com:8443')
            ->and($recovered['text'])->toContain('recovered')
            ->and($opened->hasHeader('Authorization'))->toBeFalse();
    } else {
        expect($opened['event'])->toBe('site_monitor.incident.opened')
            ->and($opened['incident_id'])->toBe($target->incidents()->firstOrFail()->id)
            ->and($opened['target_id'])->toBe($target->id)
            ->and($opened['target_name'])->toBe('Delivery')
            ->and($opened['target_url'])->toBe('https://delivery.example.com:8443')
            ->and($opened['state'])->toBe('failing')
            ->and($opened['failure_count'])->toBe(1)
            ->and($opened['status_code'])->toBe(503)
            ->and($opened['error_type'])->toBe('http_status')
            ->and($opened['checked_at'])->toBe($run->checked_at->toIso8601String())
            ->and($opened['resolved_at'])->toBeNull()
            ->and($opened->hasHeader('Authorization', 'Bearer receiver-secret'))->toBeTrue()
            ->and($recovered['event'])->toBe('site_monitor.incident.resolved')
            ->and($recovered['incident_id'])->toBe($opened['incident_id'])
            ->and($recovered['state'])->toBe('passing')
            ->and($recovered['status_code'])->toBe(200)
            ->and($recovered['resolved_at'])->not->toBeNull();
    }
})->with(['slack', 'webhook']);

it('disables recovery for both HTTP channels while still sending opening alerts', function (): void {
    fakeAlertDns();
    $http = fakeSiteMonitorAlertHttp()->fake();
    config()->set('capell-site-monitor.alerts', [
        'slack_url' => 'https://alerts.example.com/slack',
        'webhook_url' => 'https://alerts.example.com/webhook',
        'notify_on_recovery' => false,
    ]);
    $target = alertTarget('Opening');
    RecordSiteMonitorRunAction::run($target, alertResult(SiteMonitorState::Failing));
    RecordSiteMonitorRunAction::run($target->refresh(), alertResult(SiteMonitorState::Passing));
    workSiteMonitorAlertQueue();

    $http->assertSentCount(2);
    $http->assertSent(static fn (Request $request): bool => $request->url() === 'https://alerts.example.com/slack');
    $http->assertSent(static fn (Request $request): bool => ($request->data()['event'] ?? null) === 'site_monitor.incident.opened');
});

it('preserves the incident and delivers the remaining channels when mail or Slack fails', function (string $failedChannel): void {
    fakeAlertDns();
    $exceptions = Exceptions::fake();
    $http = fakeSiteMonitorAlertHttp()->fake([
        'https://alerts.example.com/slack' => Http::response('', $failedChannel === 'slack' ? 500 : 200),
        'https://alerts.example.com/webhook' => Http::response('', 204),
    ]);
    config()->set('capell-site-monitor.alerts', [
        'mail_to' => $failedChannel === 'mail' ? 'ops@example.com' : '',
        'slack_url' => 'https://alerts.example.com/slack',
        'webhook_url' => 'https://alerts.example.com/webhook',
    ]);
    if ($failedChannel === 'mail') {
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP credential-secret'));
        $factory = Mockery::mock(MailFactory::class);
        $factory->shouldReceive('mailer')->once()->andReturn($mailer);
        app()->instance(MailFactory::class, $factory);
    }

    $target = alertTarget('Survives');
    RecordSiteMonitorRunAction::run($target, alertResult(SiteMonitorState::Failing));
    workSiteMonitorAlertQueue();

    expect($target->refresh()->current_state)->toBe(SiteMonitorState::Failing)
        ->and($target->incidents()->firstOrFail()->status->value)->toBe('open');
    $http->assertSentCount(2);
    $http->assertSent(static fn (Request $request): bool => ($request->data()['event'] ?? null) === 'site_monitor.incident.opened');
    $exceptions->assertReportedCount(1);
    $exception = $exceptions->reported()[0];
    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toContain($failedChannel)
        ->not->toContain('credential-secret')
        ->and($exception->getPrevious())->toBeNull();
})->with(['mail', 'slack']);

it('refuses unsafe alert destinations and reports failure without exposing webhook secrets', function (string $url): void {
    fakeAlertDns();
    Exceptions::fake();
    $http = fakeSiteMonitorAlertHttp()->fake();
    config()->set('capell-site-monitor.allow_private_targets', true);
    config()->set('capell-site-monitor.alerts.webhook_url', $url);
    config()->set('capell-site-monitor.alerts.mail_to', '');
    $target = alertTarget('Guarded');

    RecordSiteMonitorRunAction::run($target, alertResult(SiteMonitorState::Failing));
    workSiteMonitorAlertQueue();

    $http->assertNothingSent();
    expect($target->incidents()->firstOrFail()->status->value)->toBe('open');
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(static fn (SiteMonitorHttpAlertDeliveryException $exception): bool => ! str_contains($exception->getMessage(), 'endpoint-secret')
        && $exception->getPrevious() === null);
})->with([
    'clear text' => 'http://alerts.example.com/endpoint-secret',
    'private address' => 'https://127.0.0.1/endpoint-secret',
    'credentials' => 'https://user:endpoint-secret@alerts.example.com/hook',
]);

it('does not forward a webhook payload through a redirect and reports the non-success response', function (): void {
    fakeAlertDns();
    Exceptions::fake();
    $redirectPolicy = null;
    $http = fakeSiteMonitorAlertHttp()->fake(function (Request $request, array $options) use (&$redirectPolicy): PromiseInterface {
        $redirectPolicy = $options['allow_redirects'] ?? null;

        return Http::response('', 302, ['Location' => 'https://elsewhere.example.com/private']);
    });
    config()->set('capell-site-monitor.alerts.webhook_url', 'https://alerts.example.com/endpoint-secret');
    $target = alertTarget('Redirect');
    RecordSiteMonitorRunAction::run($target, alertResult(SiteMonitorState::Failing));
    workSiteMonitorAlertQueue();

    $http->assertSentCount(1);
    expect($redirectPolicy)->toBeFalse();
    Exceptions::assertReportedCount(1);
});

it('requires a Core release that provides the HTTP alert transport', function (): void {
    $composer = capell_json_file_array(dirname(__DIR__, 2) . '/composer.json');
    $constraint = data_get($composer, 'require.capell-app/core');
    throw_unless(is_string($constraint), RuntimeException::class, 'Site Monitor must declare a Core dependency.');

    // 1.0.78 ships the HTTP alert transport; the floor may sit at a later
    // release that the next full release publishes alongside this package.
    expect(Semver::satisfies('1.0.77', $constraint))->toBeFalse()
        ->and(Semver::satisfies('1.0.79', $constraint))->toBeTrue();

    $lock = capell_json_file_array(dirname(__DIR__, 4) . '/composer.lock');
    $packages = $lock['packages'] ?? null;
    throw_unless(is_array($packages), RuntimeException::class, 'The root Composer lock must list its packages.');
    $core = array_find($packages, static fn (mixed $package): bool => data_get($package, 'name') === 'capell-app/core');
    $version = data_get($core, 'version');
    throw_unless(is_string($version), RuntimeException::class, 'The root Composer lock must pin a Core version.');

    // The lock that tests run against must carry the transport, even while the
    // floor names a Core release that is not yet tagged.
    expect(Semver::satisfies($version, '^1.0.78'))->toBeTrue(sprintf('Locked Core %s must provide the HTTP alert transport (1.0.78 or later).', $version));
});

it('delivers credentialled HTTP alerts outside outgoing request telemetry', function (string $channel, bool $fails): void {
    fakeAlertDns();
    Exceptions::fake();
    $url = 'https://alerts.example.com/private-endpoint?key=receiver-key';
    $observed = [];
    app()->afterResolving(Factory::class, static function (Factory $factory) use (&$observed): void {
        $factory->globalRequestMiddleware(static function (RequestInterface $request) use (&$observed): RequestInterface {
            $observed[] = (string) $request->getUri();

            return $request;
        });
    });
    Http::clearResolvedInstance(Factory::class);
    app()->forgetInstance(Factory::class);
    Http::preventStrayRequests();
    Http::fake(['https://public.example.com/ping' => Http::response('', 204)]);
    $http = fakeSiteMonitorAlertHttp()->fake([$url => $fails ? Http::failedConnection() : Http::response('', 204)]);
    Event::listen([RequestSending::class, ResponseReceived::class, ConnectionFailed::class], function (object $event) use (&$observed): void {
        $observed[] = $event::class;
    });
    config()->set('capell-site-monitor.alerts.' . $channel . '_url', $url);
    config()->set('capell-site-monitor.alerts.webhook_token', 'receiver-secret');

    RecordSiteMonitorRunAction::run(alertTarget('Private receiver'), alertResult(SiteMonitorState::Failing));
    workSiteMonitorAlertQueue();

    $http->assertSent(static fn (Request $request): bool => $request->url() === $url);
    expect($observed)->toBeEmpty();
    Exceptions::assertReportedCount((int) $fails);

    Http::get('https://public.example.com/ping');
    expect($observed)->toBe(['https://public.example.com/ping', RequestSending::class, ResponseReceived::class]);
})->with(['slack', 'webhook'])->with([false, true]);

it('keeps HTTP credentials out of notification sending and sent events', function (string $channel): void {
    fakeAlertDns();
    $http = fakeSiteMonitorAlertHttp()->fake();
    $url = 'https://alerts.example.com/notification-secret';
    config()->set('capell-site-monitor.alerts.' . $channel . '_url', $url);
    config()->set('capell-site-monitor.alerts.webhook_token', 'notification-bearer');
    $events = [];
    Event::listen([NotificationSending::class, NotificationSent::class], static function (NotificationSending|NotificationSent $event) use (&$events): void {
        $events[] = $event;
    });

    RecordSiteMonitorRunAction::run(alertTarget('Event receiver'), alertResult(SiteMonitorState::Failing));
    workSiteMonitorAlertQueue();

    $http->assertSent(static fn (Request $request): bool => $request->url() === $url);
    expect(array_map(static fn (object $event): string => $event::class, $events))->toBe([NotificationSending::class, NotificationSent::class]);
    foreach ($events as $event) {
        $payload = serialize($event);
        expect($payload)->not->toContain($url)
            ->not->toContain('/notification-secret')
            ->not->toContain('notification-bearer');
    }
})->with(['slack', 'webhook']);

it('sanitises HTTP connection failures before dispatching a failed notification event', function (string $channel): void {
    fakeAlertDns();
    Exceptions::fake();
    $url = 'https://alerts.example.com/failed-notification-secret';
    fakeSiteMonitorAlertHttp()->fake(static function () use ($url): never {
        throw new ConnectionException('Connection timed out for ' . $url);
    });
    config()->set('capell-site-monitor.alerts.' . $channel . '_url', $url);
    $events = [];
    Event::listen(NotificationFailed::class, static function (NotificationFailed $event) use (&$events): void {
        $events[] = $event;
    });

    RecordSiteMonitorRunAction::run(alertTarget('Failed event receiver'), alertResult(SiteMonitorState::Failing));
    workSiteMonitorAlertQueue();

    expect($events)->toHaveCount(1);
    $event = $events[0];
    $exception = $event->data['exception'];
    expect($exception)->toBeInstanceOf(Throwable::class);
    assert($exception instanceof Throwable);
    expect($exception->getMessage())->not->toContain($url)
        ->not->toContain('/failed-notification-secret')
        ->and($exception->getPrevious())->toBeNull()
        ->and($exception->getTraceAsString())->not->toContain('/failed-notification-secret')
        ->and(serialize([$event->notifiable, $event->notification, $event->channel]))->not->toContain($url)
        ->not->toContain('/failed-notification-secret');
    Exceptions::assertReported(static fn (SiteMonitorHttpAlertDeliveryException $reported): bool => str_contains($reported->getMessage(), $channel)
        && str_contains($reported->getMessage(), ConnectionException::class)
        && ! str_contains($reported->getMessage(), '/failed-notification-secret')
        && $reported->getPrevious() === null);
})->with(['slack', 'webhook']);

it('leaves response compression untouched and keeps the wire-size guard for status-only alerts', function (string $channel): void {
    fakeAlertDns();
    Exceptions::fake();
    $requestOptions = [];
    $http = fakeSiteMonitorAlertHttp()->fake(function (Request $request, array $options) use (&$requestOptions): PromiseInterface {
        $requestOptions = $options;

        return Http::response(str_repeat('x', 64 * 1024 + 1), 200, ['Content-Encoding' => 'gzip']);
    });
    config()->set('capell-site-monitor.alerts.' . $channel . '_url', 'https://alerts.example.com/hook');

    RecordSiteMonitorRunAction::run(alertTarget('Large response'), alertResult(SiteMonitorState::Failing));
    workSiteMonitorAlertQueue();

    $http->assertSentCount(1);
    expect($requestOptions['decode_content'] ?? null)->toBeFalse()
        ->and($requestOptions['curl'][CURLOPT_RESOLVE] ?? null)->toBe(['alerts.example.com:443:93.184.216.34']);
    Exceptions::assertReportedCount(1);
})->with(['slack', 'webhook']);

it('enqueues one timed delivery per channel only once the incident commits', function (bool $useFailover): void {
    Notification::fake();
    if ($useFailover) {
        config()->set('queue.default', 'alert-failover');
        config()->set('queue.connections.alert-failover', ['driver' => 'failover', 'connections' => ['site-monitor-alerts']]);
    }
    config()->set('capell-site-monitor.alerts', [
        'mail_to' => 'ops@example.com',
        'slack_url' => 'https://alerts.example.com/slack-secret',
        'webhook_url' => 'https://alerts.example.com/webhook-secret',
        'webhook_token' => 'bearer-secret',
    ]);

    DB::beginTransaction();
    RecordSiteMonitorRunAction::run(alertTarget('Rolled back'), alertResult(SiteMonitorState::Failing));
    DB::rollBack();
    expect(Queue::size())->toBe(0);

    $target = alertTarget('Queue budget');
    DB::transaction(static function () use ($target): void {
        RecordSiteMonitorRunAction::run($target, alertResult(SiteMonitorState::Failing));

        expect(Queue::size())->toBe(0);
        Notification::assertNothingSent();
    });

    Notification::assertNothingSent();
    expect($target->incidents()->firstOrFail()->status->value)->toBe('open')
        ->and(Queue::size())->toBe(3);
    $payloads = DB::connection('site-monitor-alerts')->table('jobs')->pluck('payload');
    foreach ($payloads as $payload) {
        throw_unless(is_string($payload), RuntimeException::class, 'Expected a serialised delivery job.');
        expect(capell_json_array($payload))->toMatchArray([
            'timeout' => 45,
            'maxTries' => 1,
            'failOnTimeout' => true,
        ]);
        foreach (['slack-secret', 'webhook-secret', 'bearer-secret', 'ops@example.com'] as $secret) {
            expect($payload)->not->toContain($secret);
        }
    }
})->with(['database' => false, 'worker-backed failover' => true]);

it('delivers all alerts through an explicit worker connection when the app default can defer jobs', function (): void {
    Notification::fake();
    config()->set('queue.default', 'alert-failover');
    config()->set('queue.connections.alert-failover', ['driver' => 'failover', 'connections' => ['site-monitor-alerts', 'deferred']]);
    config()->set('queue.connections.deferred', ['driver' => 'deferred']);
    config()->set('capell-site-monitor.alerts', [
        'queue_connection' => 'site-monitor-alerts',
        'mail_to' => 'ops@example.com',
        'slack_url' => 'https://alerts.example.com/slack',
        'webhook_url' => 'https://alerts.example.com/webhook',
    ]);

    RecordSiteMonitorRunAction::run(alertTarget('Dedicated alerts'), alertResult(SiteMonitorState::Failing));

    Notification::assertNothingSent();
    expect(Queue::connection('site-monitor-alerts')->size())->toBe(3);
    workSiteMonitorAlertQueue();
    Notification::assertSentOnDemandTimes(SiteMonitorIncidentNotification::class, 3);
});

it('skips incident alerts without a safe worker-backed connection', function (string $connection): void {
    Notification::fake();
    $log = Log::spy();
    config()->set('queue.default', $connection);
    config()->set('queue.connections.alert-failover', ['driver' => 'failover', 'connections' => ['sync']]);
    config()->set('queue.connections.mixed-alert-failover', ['driver' => 'failover', 'connections' => ['site-monitor-alerts', 'deferred']]);
    config()->set('queue.connections.deferred', ['driver' => 'deferred']);
    config()->set('queue.connections.cyclic-alert-failover', ['driver' => 'failover', 'connections' => ['cyclic-alert-failover']]);
    config()->set('capell-site-monitor.alerts.mail_to', 'ops@example.com');

    $target = alertTarget('Inline queue');
    RecordSiteMonitorRunAction::run($target, alertResult(SiteMonitorState::Failing));

    expect($target->incidents()->firstOrFail()->status->value)->toBe('open');
    expect(Queue::connection('site-monitor-alerts')->size())->toBe(0);
    Notification::assertNothingSent();
    $log->shouldHaveReceived('warning')->with('Site Monitor incident alerts require a worker-backed queue connection.')->once();
})->with(['sync', 'deferred', 'alert-failover', 'mixed-alert-failover', 'cyclic-alert-failover', 'unknown-alert-queue']);

it('queues the remaining channels when a queue push fails', function (): void {
    Notification::fake();
    $log = Log::spy();
    config()->set('capell-site-monitor.alerts', [
        'mail_to' => 'ops@example.com',
        'slack_url' => 'https://alerts.example.com/slack',
        'webhook_url' => 'https://alerts.example.com/webhook',
    ]);
    $firstPush = true;
    Event::listen(JobQueueing::class, static function () use (&$firstPush): void {
        if ($firstPush) {
            $firstPush = false;

            throw new RuntimeException('Broker credentials are private.');
        }
    });

    $target = alertTarget('Queue fault');
    RecordSiteMonitorRunAction::run($target, alertResult(SiteMonitorState::Failing));

    expect($target->incidents()->firstOrFail()->status->value)->toBe('open')
        ->and(Queue::size())->toBe(2);
    $log->shouldHaveReceived('warning')->with('Site Monitor incident alert could not be queued.', [
        'channel' => 'mail',
        'exception_type' => RuntimeException::class,
    ])->once();

    workSiteMonitorAlertQueue();

    Notification::assertSentOnDemandTimes(SiteMonitorIncidentNotification::class, 2);
    Notification::assertSentOnDemand(SiteMonitorIncidentNotification::class, static fn (SiteMonitorIncidentNotification $notification, array $channels): bool => $channels === [SiteMonitorSlackChannel::class]);
    Notification::assertSentOnDemand(SiteMonitorIncidentNotification::class, static fn (SiteMonitorIncidentNotification $notification, array $channels): bool => $channels === [SiteMonitorWebhookChannel::class]);
});

it('skips a queued alert when the operator removes its destination', function (): void {
    Notification::fake();
    config()->set('capell-site-monitor.alerts.webhook_url', 'https://alerts.example.com/retired-webhook');

    RecordSiteMonitorRunAction::run(alertTarget('Retired receiver'), alertResult(SiteMonitorState::Failing));

    expect(Queue::size())->toBe(1);
    config()->set('capell-site-monitor.alerts.webhook_url', '');
    workSiteMonitorAlertQueue();

    expect(Queue::size())->toBe(0);
    Notification::assertNothingSent();
});

it('reports a timed-out delivery and leaves the next channel in the queue', function (): void {
    Notification::fake();
    Exceptions::fake();
    config()->set('queue.failed.driver', 'null');
    config()->set('capell-site-monitor.alerts', [
        'slack_url' => 'https://alerts.example.com/slack',
        'webhook_url' => 'https://alerts.example.com/webhook',
    ]);

    RecordSiteMonitorRunAction::run(alertTarget('Timed-out worker'), alertResult(SiteMonitorState::Failing));

    $job = Queue::connection()->pop() ?? throw new RuntimeException('Expected a queued Slack delivery.');
    $job->fail(TimeoutExceededException::forJob($job));

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(static fn (RuntimeException $exception): bool => $exception->getMessage() === 'Site Monitor incident alert delivery timed out for slack.'
        && $exception->getPrevious() === null);
    expect(Queue::size())->toBe(1);

    workSiteMonitorAlertQueue();

    Notification::assertSentOnDemandTimes(SiteMonitorIncidentNotification::class, 1);
    Notification::assertSentOnDemand(SiteMonitorIncidentNotification::class, static fn (SiteMonitorIncidentNotification $notification, array $channels): bool => $channels === [SiteMonitorWebhookChannel::class]);
});
