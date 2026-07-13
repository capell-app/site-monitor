<?php

declare(strict_types=1);

use Capell\SiteMonitor\Actions\GuardSiteMonitorOutboundUrlAction;
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
]);

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
