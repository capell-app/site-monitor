<?php

declare(strict_types=1);

use Capell\SiteMonitor\Actions\GuardSiteMonitorOutboundUrlAction;
use Capell\SiteMonitor\Actions\ResolveSiteMonitorSocketAddressAction;
use Capell\SiteMonitor\Contracts\SiteMonitorHostResolver;
use Capell\SiteMonitor\Exceptions\UnsafeSiteMonitorTargetException;

it('allows public http and https targets and returns the normalized host', function (): void {
    expect(GuardSiteMonitorOutboundUrlAction::run(' HTTPS://Example.COM./health '))
        ->toBe('example.com');
});

it('rejects unsupported schemes and missing hosts before any outbound check', function (string $url, string $errorType): void {
    expect(captureUnsafeSiteMonitorTarget($url)->errorType)->toBe($errorType);
})->with([
    'javascript scheme' => ['javascript:alert(1)', 'unsupported_target_scheme'],
    'file scheme' => ['file:///etc/passwd', 'unsupported_target_scheme'],
    'missing host' => ['https://', 'invalid_target_url'],
]);

it('rejects local hostnames without relying on DNS', function (string $url): void {
    expect(captureUnsafeSiteMonitorTarget($url)->errorType)->toBe('unsafe_target_host');
})->with([
    'localhost' => ['http://localhost/internal'],
    'subdomain localhost' => ['http://admin.localhost/internal'],
    'single label intranet' => ['http://intranet/internal'],
]);

it('rejects private and reserved literal IP targets without relying on DNS', function (string $url): void {
    expect(captureUnsafeSiteMonitorTarget($url)->errorType)->toBe('unsafe_target_address');
})->with([
    'private ipv4' => ['http://10.0.0.5/internal'],
    'loopback ipv4' => ['http://127.0.0.1/internal'],
    'link-local ipv4' => ['http://169.254.1.10/internal'],
    'unspecified ipv4' => ['http://0.0.0.0/internal'],
    'broadcast ipv4' => ['http://255.255.255.255/internal'],
    'private ipv6' => ['http://[fd00::1]/internal'],
    'loopback ipv6' => ['http://[::1]/internal'],
    'shared address space' => ['http://100.64.0.1/internal'],
    'shared address space upper bound' => ['http://100.127.255.254/internal'],
    'benchmarking' => ['http://198.18.0.1/internal'],
    'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/internal'],
    'ipv4-mapped private' => ['http://[::ffff:10.0.0.1]/internal'],
    'ipv4-mapped metadata' => ['http://[::ffff:169.254.169.254]/internal'],
    'nat64 well-known loopback' => ['http://[64:ff9b::7f00:1]/internal'],
    'nat64 local-use' => ['http://[64:ff9b:1::a00:1]/internal'],
    '6to4 loopback' => ['http://[2002:7f00:1::1]/internal'],
    'teredo' => ['http://[2001:0:4136:e378:8000:63bf:3fff:fdd2]/internal'],
    'link-local ipv6' => ['http://[fe80::1]/internal'],
    'multicast ipv6' => ['http://[ff02::1]/internal'],
]);

it('rejects hostnames when any resolved address is not public', function (string $address): void {
    $resolver = Mockery::mock(SiteMonitorHostResolver::class);
    $resolver->shouldReceive('addresses')->with('monitor.example.com')->andReturn(['93.184.216.34', $address]);
    app()->instance(SiteMonitorHostResolver::class, $resolver);

    expect(captureUnsafeSiteMonitorTarget('https://monitor.example.com/health')->errorType)->toBe('unsafe_target_address');
})->with([
    'shared address space' => ['100.64.12.1'],
    'ipv4-mapped' => ['::ffff:127.0.0.1'],
    'nat64' => ['64:ff9b::a9fe:a9fe'],
]);

it('pins the connection to a resolved address that passed the same guard', function (): void {
    $resolver = Mockery::mock(SiteMonitorHostResolver::class);
    $resolver->shouldReceive('addresses')->with('monitor.example.com')->andReturn(['100.64.0.9']);
    app()->instance(SiteMonitorHostResolver::class, $resolver);

    expect(fn (): string => ResolveSiteMonitorSocketAddressAction::run('monitor.example.com'))
        ->toThrow(UnsafeSiteMonitorTargetException::class, 'private or reserved');
});

it('can allow private targets only outside production when explicitly configured', function (): void {
    config()->set('capell-site-monitor.allow_private_targets', true);

    expect(GuardSiteMonitorOutboundUrlAction::run('http://10.0.0.5/internal'))
        ->toBe('10.0.0.5');
});

function captureUnsafeSiteMonitorTarget(string $url): UnsafeSiteMonitorTargetException
{
    try {
        GuardSiteMonitorOutboundUrlAction::run($url);
    } catch (UnsafeSiteMonitorTargetException $exception) {
        return $exception;
    }

    throw new LogicException('Expected the site monitor target to be rejected.');
}
