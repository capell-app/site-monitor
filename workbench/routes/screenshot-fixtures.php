<?php

declare(strict_types=1);

use Capell\SiteMonitor\Actions\Fixtures\PrepareSiteMonitorScreenshotAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

$incidentRouteKey = static function (Model $incident): int|string {
    $routeKey = $incident->getRouteKey();
    throw_unless(is_int($routeKey) || is_string($routeKey), RuntimeException::class, 'The Site Monitor screenshot incident route key must be scalar.');

    return $routeKey;
};

Route::middleware(['web', 'auth'])->get('/screenshot-fixtures/site-monitor/{screen}', static function (string $screen) use ($incidentRouteKey): RedirectResponse {
    abort_unless(in_array($screen, ['target', 'targets', 'incident'], true), 404);
    $target = resolve(PrepareSiteMonitorScreenshotAction::class)->handle();
    $targetRouteKey = $target->getRouteKey();
    throw_unless(is_int($targetRouteKey) || is_string($targetRouteKey), RuntimeException::class, 'The Site Monitor screenshot target route key must be scalar.');

    return redirect(match ($screen) {
        'target' => '/admin/site-monitor/targets/' . $targetRouteKey . '/edit',
        'incident' => '/admin/site-monitor/incidents/' . $incidentRouteKey($target->openIncident()->firstOrFail()) . '/edit',
        default => '/admin/site-monitor/targets',
    });
});
