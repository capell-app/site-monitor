<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('keeps committed Site Monitor dashboard and incident proof screenshots current', function (): void {
    $packagePath = dirname(__DIR__, 2);
    $repositoryPath = dirname(__DIR__, 4);
    $entries = [
        [
            'htmlPath' => $packagePath . '/docs/screenshots/.site-monitor-dashboard.html',
            'screenshotPath' => $packagePath . '/docs/screenshots/site-monitor-dashboard.png',
            'html' => siteMonitorDashboardScreenshotHtml(),
            'width' => 1280,
            'height' => 900,
            'expected' => 'Site Monitor dashboard',
        ],
        [
            'htmlPath' => $packagePath . '/docs/screenshots/.site-monitor-incident.html',
            'screenshotPath' => $packagePath . '/docs/screenshots/site-monitor-incident.png',
            'html' => siteMonitorIncidentScreenshotHtml(),
            'width' => 1280,
            'height' => 900,
            'expected' => 'Incident evidence',
        ],
    ];

    foreach ($entries as $entry) {
        expect($entry['html'])
            ->toContain($entry['expected'])
            ->not->toContain('authoring')
            ->not->toContain('recordKey')
            ->not->toContain('capell-app/site-monitor');

        if (getenv('CAPELL_REFRESH_SITE_MONITOR_SCREENSHOTS') === '1') {
            file_put_contents($entry['htmlPath'], $entry['html']);

            try {
                $process = new Process([
                    'node',
                    $repositoryPath . '/scripts/capture-static-html-screenshot.mjs',
                    $entry['htmlPath'],
                    $entry['screenshotPath'],
                    (string) $entry['width'],
                    (string) $entry['height'],
                ], $repositoryPath);
                $process->setTimeout(60);
                $process->mustRun();
            } finally {
                if (is_file($entry['htmlPath'])) {
                    unlink($entry['htmlPath']);
                }
            }
        }

        expect($entry['screenshotPath'])->toBeFile();

        $dimensions = getimagesize($entry['screenshotPath']);

        expect($dimensions)->toBeArray()
            ->and($dimensions[0] ?? null)->toBe($entry['width'])
            ->and($dimensions[1] ?? null)->toBeGreaterThanOrEqual($entry['height']);
    }
});

function siteMonitorDashboardScreenshotHtml(): string
{
    return siteMonitorScreenshotShell('Site Monitor dashboard', <<<'HTML'
<section class="hero">
    <div>
        <p class="eyebrow">Operations admin</p>
        <h1>Site Monitor dashboard</h1>
        <p>External uptime, SSL, domain expiry, and incident monitoring in one package-owned operations surface.</p>
    </div>
    <button>Run due checks</button>
</section>
<section class="stats">
    <article><span>Total targets</span><strong>24</strong></article>
    <article><span>Enabled targets</span><strong>22</strong></article>
    <article><span>Passing</span><strong>19</strong></article>
    <article><span>Warning</span><strong>2</strong></article>
    <article><span>Failing</span><strong>1</strong></article>
    <article><span>Open incidents</span><strong>3</strong></article>
</section>
<section class="grid">
    <article class="panel"><span>Latest checked</span><strong>Tue, Jun 16, 2026 9:42 AM</strong></article>
    <article class="panel"><span>Oldest open incident</span><strong>Tue, Jun 16, 2026 8:10 AM</strong></article>
    <article class="panel"><span>Median response</span><strong>184 ms</strong></article>
</section>
<section class="panel">
    <h2>Target state</h2>
    <table>
        <thead><tr><th>Target</th><th>Check</th><th>State</th><th>Response</th><th>Next check</th></tr></thead>
        <tbody>
            <tr><td>Marketing homepage<br><small>https://example.com</small></td><td>HTTP</td><td><span class="badge green">Passing</span></td><td>118 ms</td><td>1 min</td></tr>
            <tr><td>Checkout certificate<br><small>checkout.example.com</small></td><td>SSL</td><td><span class="badge amber">Warning</span></td><td>Expires in 18 days</td><td>4 min</td></tr>
            <tr><td>Support portal<br><small>https://support.example.com</small></td><td>HTTP</td><td><span class="badge red">Failing</span></td><td>503</td><td>Queued</td></tr>
        </tbody>
    </table>
</section>
HTML);
}

function siteMonitorIncidentScreenshotHtml(): string
{
    return siteMonitorScreenshotShell('Incident evidence', <<<'HTML'
<section class="hero">
    <div>
        <p class="eyebrow">Incident tracking</p>
        <h1>Incident evidence</h1>
        <p>Operators inspect failure evidence, status, severity, and resolution state without leaving Capell admin.</p>
    </div>
    <button>Mark resolved</button>
</section>
<section class="grid">
    <article class="panel"><span>Status</span><strong>Open</strong></article>
    <article class="panel"><span>Severity</span><strong>critical</strong></article>
    <article class="panel"><span>Failure count</span><strong>3</strong></article>
</section>
<section class="panel">
    <h2>Open incidents</h2>
    <table>
        <thead><tr><th>Target</th><th>Status</th><th>Severity</th><th>Summary</th><th>Opened</th></tr></thead>
        <tbody>
            <tr><td>Support portal</td><td><span class="badge red">Open</span></td><td>critical</td><td>HTTP check returned 503 after three consecutive failures.</td><td>8:10 AM</td></tr>
            <tr><td>Checkout certificate</td><td><span class="badge amber">Open</span></td><td>warning</td><td>SSL certificate expires in 18 days.</td><td>8:34 AM</td></tr>
            <tr><td>Primary domain</td><td><span class="badge green">Resolved</span></td><td>warning</td><td>RDAP expiry warning resolved after renewal.</td><td>Yesterday</td></tr>
        </tbody>
    </table>
</section>
<section class="panel evidence">
    <h2>Latest failure evidence</h2>
    <pre>{
  "url": "https://support.example.com",
  "state": "failing",
  "status": 503,
  "response_ms": 842,
  "checked_at": "2026-06-16T09:42:00+00:00"
}</pre>
</section>
HTML);
}

function siteMonitorScreenshotShell(string $title, string $body): string
{
    return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{$title}</title>
    <style>
        body { margin: 0; background: #f7f8fb; color: #111827; font-family: Inter, ui-sans-serif, system-ui, sans-serif; }
        main { padding: 36px; }
        .hero { display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; margin-bottom: 22px; }
        .eyebrow { margin: 0 0 8px; color: #2563eb; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 0; font-size: 36px; line-height: 1.1; }
        p { max-width: 760px; color: #5b6472; line-height: 1.65; }
        button { border: 0; border-radius: 8px; background: #2563eb; color: white; padding: 11px 14px; font-weight: 800; }
        .stats { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin-bottom: 18px; }
        .stats article, .panel { border: 1px solid #dbe1ea; border-radius: 10px; background: white; box-shadow: 0 16px 42px rgba(17, 24, 39, .07); }
        .stats article { padding: 16px; }
        span, small { color: #667085; }
        strong { display: block; margin-top: 6px; font-size: 26px; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 18px; }
        .panel { padding: 18px; margin-bottom: 18px; }
        h2 { margin: 0 0 14px; font-size: 18px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 13px 12px; border-bottom: 1px solid #edf0f4; text-align: left; vertical-align: top; }
        th { color: #667085; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .badge { display: inline-flex; border-radius: 999px; padding: 4px 9px; font-weight: 800; font-size: 12px; }
        .green { background: #ecfdf3; color: #027a48; }
        .amber { background: #fffaeb; color: #b54708; }
        .red { background: #fef3f2; color: #b42318; }
        pre { margin: 0; overflow: auto; border-radius: 8px; background: #111827; color: #e5e7eb; padding: 16px; font-size: 13px; line-height: 1.6; }
    </style>
</head>
<body><main>{$body}</main></body>
</html>
HTML;
}
