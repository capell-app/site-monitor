<?php

declare(strict_types=1);

it('declares the implemented package surfaces in capell manifest', function (): void {
    $manifest = siteMonitorManifestArray();

    expect(siteMonitorStringList(data_get($manifest, 'providers.runtime')))->toContain('Capell\\SiteMonitor\\Providers\\SiteMonitorServiceProvider')
        ->and(siteMonitorStringList(data_get($manifest, 'providers.admin')))->toContain('Capell\\SiteMonitor\\Providers\\AdminServiceProvider')
        ->and(data_get($manifest, 'database.migrations'))->toBeTrue()
        ->and(data_get($manifest, 'commands.doctor'))->toBe('capell:site-monitor:doctor')
        ->and(siteMonitorContributionTypes(data_get($manifest, 'contributes')))->toContain('admin-page', 'admin-resource', 'model', 'scheduled-job')
        ->and(siteMonitorArray(data_get($manifest, 'healthChecks')))->not->toBeEmpty()
        ->and(siteMonitorStringList(data_get($manifest, 'security.publicSurface.routeNames')))->toBe([]);
});

/**
 * @return array<string, mixed>
 */
function siteMonitorManifestArray(): array
{
    $contents = file_get_contents(dirname(__DIR__, 2) . '/capell.json');

    if (! is_string($contents)) {
        throw new RuntimeException('Unable to read Site Monitor manifest.');
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException('Site Monitor manifest must decode to an array.');
    }

    $manifest = [];

    foreach ($decoded as $key => $value) {
        if (is_string($key)) {
            $manifest[$key] = $value;
        }
    }

    return $manifest;
}

/**
 * @return array<int, mixed>
 */
function siteMonitorArray(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * @return list<string>
 */
function siteMonitorStringList(mixed $value): array
{
    return array_values(array_filter(
        siteMonitorArray($value),
        static fn (mixed $item): bool => is_string($item),
    ));
}

/**
 * @return list<string>
 */
function siteMonitorContributionTypes(mixed $value): array
{
    $types = [];

    foreach (siteMonitorArray($value) as $contribution) {
        if (! is_array($contribution)) {
            continue;
        }

        $type = $contribution['type'] ?? null;

        if (is_string($type)) {
            $types[] = $type;
        }
    }

    return $types;
}
