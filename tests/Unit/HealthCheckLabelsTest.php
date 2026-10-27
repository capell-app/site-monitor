<?php

declare(strict_types=1);

use Capell\SiteMonitor\Health\SiteMonitorHealthCheck;

it('keeps diagnostic identities while naming checks by package and purpose', function (): void {
    $results = SiteMonitorHealthCheck::runDiagnostics();
    $existingIds = [
        'legacy.site.monitor.storage',
        'legacy.site.monitor.command',
        'legacy.site.monitor.freshness',
        'legacy.site.monitor.incidents',
    ];

    expect($results)->not->toBeEmpty();

    foreach ($results as $result) {
        expect($result->label)->toStartWith('Site Monitor: ')
            ->not->toMatch('/morph|contributor|registry|binding|runtime surfaces/i');
        expect($existingIds)->toContain($result->id);
    }
});
