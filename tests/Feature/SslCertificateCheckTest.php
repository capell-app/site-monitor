<?php

declare(strict_types=1);

use Capell\SiteMonitor\Actions\ReadSiteMonitorCertificateAction;
use Capell\SiteMonitor\Actions\RunSiteMonitorCheckAction;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Jobs\RunSiteMonitorTargetJob;
use Capell\SiteMonitor\Models\SiteMonitorRun;
use Capell\SiteMonitor\Support\SiteMonitorClock;
use Capell\SiteMonitor\Support\SiteMonitorDeadline;
use Capell\SiteMonitor\Tests\Fixtures\SelfSignedTlsServer;
use Capell\SiteMonitor\Tests\Fixtures\SiteMonitorTargetFactory;
use Carbon\CarbonImmutable;

it('checks the certificate on the port in the target URL and flags an untrusted chain', function (): void {
    config()->set('capell-site-monitor.allow_private_targets', true);
    $server = SelfSignedTlsServer::start(connections: 2);

    try {
        $target = SiteMonitorTargetFactory::make(SiteMonitorCheckType::SslCertificate, [
            'url' => 'https://127.0.0.1:' . $server->port,
        ]);

        $result = RunSiteMonitorCheckAction::run($target);
    } finally {
        $server->stop();
    }

    expect($result->state)->toBe(SiteMonitorState::Failing)
        ->and($result->errorType)->toBe('ssl_certificate_untrusted')
        ->and($result->expiresAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($result->expiresAt?->isFuture())->toBeTrue();
});

it('still reports an expired certificate as expired rather than untrusted', function (): void {
    config()->set('capell-site-monitor.allow_private_targets', true);
    $server = SelfSignedTlsServer::start(connections: 2, validDays: 0);

    try {
        $result = RunSiteMonitorCheckAction::run(SiteMonitorTargetFactory::make(SiteMonitorCheckType::SslCertificate, [
            'url' => 'https://127.0.0.1:' . $server->port,
        ]));
    } finally {
        $server->stop();
    }

    expect($result->state)->toBe(SiteMonitorState::Failing)
        ->and($result->errorType)->toBe('expired');
});

it('reports the certificate as unavailable when nothing answers on the target port', function (): void {
    config()->set('capell-site-monitor.allow_private_targets', true);

    $result = RunSiteMonitorCheckAction::run(SiteMonitorTargetFactory::make(SiteMonitorCheckType::SslCertificate, [
        'url' => 'https://127.0.0.1:1',
        'timeout_ms' => 500,
    ]));

    expect($result->errorType)->toBe('ssl_certificate_unavailable');
});

it('keeps a trusted chain with a wrong hostname failing while recording its expiry', function (): void {
    config()->set('capell-site-monitor.allow_private_targets', true);
    $server = SelfSignedTlsServer::start(connections: 3);
    $options = stream_context_get_options(stream_context_get_default());
    $originalCafile = $options['ssl']['cafile'] ?? (ini_get('openssl.cafile') ?: openssl_get_cert_locations()['default_cert_file']);
    stream_context_set_option(stream_context_get_default(), 'ssl', 'cafile', $server->certificatePath);

    try {
        $failure = '';
        $expiry = (new ReadSiteMonitorCertificateAction)->handle('127.0.0.1', '127.0.0.1', $server->port, new SiteMonitorDeadline(5000), true, $failure);
        expect($expiry)->toBeNull()
            ->and($failure)->toContain('did not match expected CN=')
            ->and(strtolower($failure))->not->toContain('verif');

        $result = RunSiteMonitorCheckAction::run(SiteMonitorTargetFactory::make(SiteMonitorCheckType::SslCertificate, [
            'url' => 'https://127.0.0.1:' . $server->port,
        ]));
    } finally {
        // A null cafile leaves an empty path in the shared context and poisons later handshakes.
        stream_context_set_option(stream_context_get_default(), 'ssl', 'cafile', $originalCafile);
        $server->stop();
    }

    expect($result->state)->toBe(SiteMonitorState::Failing)
        ->and($result->errorType)->toBe('ssl_certificate_untrusted')
        ->and($result->expiresAt?->isFuture())->toBeTrue();
});

it('gives the fallback only the remaining monotonic budget and records exhaustion', function (float $elapsed, int $attempts): void {
    config()->set('capell-site-monitor.allow_private_targets', true);
    config()->set('capell-site-monitor.max_redirects', 0);
    $clock = Mockery::mock(SiteMonitorClock::class);
    $now = 100.0;
    $clock->shouldReceive('now')->andReturnUsing(static function () use (&$now): float {
        return $now;
    });
    app()->instance(SiteMonitorClock::class, $clock);
    $reader = Mockery::mock(ReadSiteMonitorCertificateAction::class);
    $budgets = [];
    $reader->shouldReceive('handle')->times($attempts)->andReturnUsing(static function (string $host, string $address, int $port, SiteMonitorDeadline $deadline, bool $verify, string &$failure) use (&$now, &$budgets, $elapsed): ?CarbonImmutable {
        $budgets[] = $deadline->remainingSeconds();
        $now += $verify ? $elapsed : $deadline->remainingSeconds();
        $failure = 'certificate verify failed';

        return null;
    });
    app()->instance(ReadSiteMonitorCertificateAction::class, $reader);

    $result = RunSiteMonitorCheckAction::run(SiteMonitorTargetFactory::make(SiteMonitorCheckType::SslCertificate, [
        'url' => 'https://93.184.216.34',
        'timeout_ms' => 50000,
    ]));

    expect($budgets)->toBe($attempts === 2 ? [50.0, 10.0] : [50.0])
        ->and($now)->toBe(150.0)
        ->and($result->state)->toBe(SiteMonitorState::Failing)
        ->and($result->errorType)->toBe('timeout');
})->with([[40.0, 2], [50.0, 1]]);

it('records a silent TLS peer as a timeout within the shared budget', function (float $connectElapsed): void {
    config()->set('capell-site-monitor.allow_private_targets', true);
    config()->set('capell-site-monitor.max_redirects', 0);
    $server = SelfSignedTlsServer::start(connections: 1, stallHandshake: true);

    // Simulate time spent connecting only after the real server has accepted TCP.
    // Wall time still advances during the real stalled handshake; there is no fake TLS reader.
    if ($connectElapsed > 0) {
        $clock = Mockery::mock(SiteMonitorClock::class);
        $clock->shouldReceive('now')->andReturnUsing(static fn (): float => hrtime(true) / 1_000_000_000 + ($server->hasAcceptedConnection() ? $connectElapsed : 0.0));
        app()->instance(SiteMonitorClock::class, $clock);
    }

    try {
        $target = SiteMonitorTargetFactory::make(SiteMonitorCheckType::SslCertificate, [
            'url' => 'https://127.0.0.1:' . $server->port,
            'timeout_ms' => 2000,
        ]);

        $startedAt = hrtime(true);
        (new RunSiteMonitorTargetJob($target->id))->handle();
        $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;

        expect($server->hasAcceptedConnection())->toBeTrue()
            // A hang ceiling only: the 2s budget plus 1s of host scheduling headroom.
            ->and($elapsed + $connectElapsed)->toBeLessThan(3.0);

        $run = SiteMonitorRun::query()->sole();
        expect($run->state)->toBe(SiteMonitorState::Failing)
            ->and($run->error_type)->toBe('timeout')
            ->and($run->expires_at)->toBeNull();
    } finally {
        $server->stop();
    }
})->with(['real stalled handshake' => [0.0], 'connect consumed most of the budget' => [1.5]]);

it('retains expiry for a trusted SAN-only certificate that does not match the host', function (): void {
    config()->set('capell-site-monitor.allow_private_targets', true);
    $server = SelfSignedTlsServer::start(connections: 4, sanOnly: true);
    $options = stream_context_get_options(stream_context_get_default());
    $originalCafile = $options['ssl']['cafile'] ?? (ini_get('openssl.cafile') ?: openssl_get_cert_locations()['default_cert_file']);
    stream_context_set_option(stream_context_get_default(), 'ssl', 'cafile', $server->certificatePath);

    try {
        $certificate = openssl_x509_parse((string) file_get_contents($server->certificatePath));
        throw_unless(is_array($certificate), RuntimeException::class, 'Could not parse the SAN-only fixture.');
        expect($certificate['subject'])->not->toHaveKey('CN')
            ->and($certificate['extensions']['subjectAltName'])->toBe('DNS:monitor.invalid');

        $failure = '';
        $trustedExpiry = (new ReadSiteMonitorCertificateAction)->handle('monitor.invalid', '127.0.0.1', $server->port, new SiteMonitorDeadline(5000), true, $failure);
        expect($trustedExpiry?->isFuture())->toBeTrue()
            ->and($failure)->toBe('');

        $expiry = (new ReadSiteMonitorCertificateAction)->handle('127.0.0.1', '127.0.0.1', $server->port, new SiteMonitorDeadline(5000), true, $failure);
        expect($expiry)->toBeNull()
            // OpenSSL words a hostname mismatch differently across versions, so assert that a
            // mismatch was reported (not a chain verification failure) rather than the wording.
            ->and($failure)->not->toBe('')
            ->and(strtolower($failure))->not->toContain('verif');

        $result = RunSiteMonitorCheckAction::run(SiteMonitorTargetFactory::make(SiteMonitorCheckType::SslCertificate, [
            'url' => 'https://127.0.0.1:' . $server->port,
        ]));
    } finally {
        stream_context_set_option(stream_context_get_default(), 'ssl', 'cafile', $originalCafile);
        $server->stop();
    }

    expect($result->state)->toBe(SiteMonitorState::Failing)
        ->and($result->errorType)->toBe('ssl_certificate_untrusted')
        ->and($result->expiresAt?->isFuture())->toBeTrue();
});
