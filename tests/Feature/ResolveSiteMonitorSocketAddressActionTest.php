<?php

declare(strict_types=1);

use Capell\SiteMonitor\Actions\ResolveSiteMonitorSocketAddressAction;
use Capell\SiteMonitor\Contracts\SiteMonitorHostResolver;
use Capell\SiteMonitor\Exceptions\UnsafeSiteMonitorTargetException;

it('refuses a private address returned after the hostname was initially validated', function (): void {
    $resolver = Mockery::mock(SiteMonitorHostResolver::class);
    $resolver->shouldReceive('addresses')->once()->with('example.com')->andReturn(['127.0.0.1']);
    app()->instance(SiteMonitorHostResolver::class, $resolver);

    expect(fn (): string => ResolveSiteMonitorSocketAddressAction::run('example.com'))
        ->toThrow(UnsafeSiteMonitorTargetException::class, 'private or reserved');
});

it('pins a public IPv6 address without requiring another hostname lookup', function (): void {
    $resolver = Mockery::mock(SiteMonitorHostResolver::class);
    $resolver->shouldReceive('addresses')->once()->with('example.com')->andReturn(['2606:4700:4700::1111']);
    app()->instance(SiteMonitorHostResolver::class, $resolver);

    expect(ResolveSiteMonitorSocketAddressAction::run('example.com'))
        ->toBe('2606:4700:4700::1111');
});

it('fails closed when certificate target resolution is empty', function (): void {
    $resolver = Mockery::mock(SiteMonitorHostResolver::class);
    $resolver->shouldReceive('addresses')->once()->with('example.com')->andReturn([]);
    app()->instance(SiteMonitorHostResolver::class, $resolver);

    expect(fn (): string => ResolveSiteMonitorSocketAddressAction::run('example.com'))
        ->toThrow(UnsafeSiteMonitorTargetException::class, 'could not be resolved');
});
