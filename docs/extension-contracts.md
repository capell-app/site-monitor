# Extension and action examples

<!-- Maintained by scripts/generate-package-readmes.php -->

Use the action functions with records and Data objects supplied by your application.
They pass each argument to the package operation and return its result.

These adapters show container registration. Use the owning package registry when
a contract requires contributor discovery.

Contract adapters wrap an existing implementation. Call their registration function
from your service provider with that implementation; tagged contracts keep their declared tag.
Resolve the backend by its concrete class before registration so the replacement contract
does not resolve itself. Static contract metadata uses one backend class per adapter.

## Contract `Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient`

<!-- example: contract Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient -->

```php
<?php
declare(strict_types=1);

namespace App\CapellExamples\SiteMonitor;

final class SiteMonitorDomainExpiryClientAdapter implements \Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient
{
    public function __construct(private readonly \Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient $backend) {}

    #[\Override]
    public function expiresAt(string $domain): ?\Carbon\CarbonImmutable
    {
        return $this->backend->expiresAt($domain);
    }
}

function registerSiteMonitorDomainExpiryClientAdapter(\Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient $backend): void
{
    app()->bind(SiteMonitorDomainExpiryClientAdapter::class, static fn (): SiteMonitorDomainExpiryClientAdapter => new SiteMonitorDomainExpiryClientAdapter($backend));
    app()->bind(\Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient::class, SiteMonitorDomainExpiryClientAdapter::class);
}
```

## Contract `Capell\SiteMonitor\Contracts\SiteMonitorHostResolver`

<!-- example: contract Capell\SiteMonitor\Contracts\SiteMonitorHostResolver -->

```php
<?php
declare(strict_types=1);

namespace App\CapellExamples\SiteMonitor;

final class SiteMonitorHostResolverAdapter implements \Capell\SiteMonitor\Contracts\SiteMonitorHostResolver
{
    public function __construct(private readonly \Capell\SiteMonitor\Contracts\SiteMonitorHostResolver $backend) {}

    #[\Override]
    public function addresses(string $host): array
    {
        return $this->backend->addresses($host);
    }
}

function registerSiteMonitorHostResolverAdapter(\Capell\SiteMonitor\Contracts\SiteMonitorHostResolver $backend): void
{
    app()->bind(SiteMonitorHostResolverAdapter::class, static fn (): SiteMonitorHostResolverAdapter => new SiteMonitorHostResolverAdapter($backend));
    app()->bind(\Capell\SiteMonitor\Contracts\SiteMonitorHostResolver::class, SiteMonitorHostResolverAdapter::class);
}
```

## Contract `Capell\SiteMonitor\Contracts\SiteMonitorHttpClient`

<!-- example: contract Capell\SiteMonitor\Contracts\SiteMonitorHttpClient -->

```php
<?php
declare(strict_types=1);

namespace App\CapellExamples\SiteMonitor;

final class SiteMonitorHttpClientAdapter implements \Capell\SiteMonitor\Contracts\SiteMonitorHttpClient
{
    public function __construct(private readonly \Capell\SiteMonitor\Contracts\SiteMonitorHttpClient $backend) {}

    #[\Override]
    public function check(\Capell\SiteMonitor\Models\SiteMonitorTarget $target): \Capell\SiteMonitor\Data\SiteMonitorCheckResultData
    {
        return $this->backend->check($target);
    }
}

function registerSiteMonitorHttpClientAdapter(\Capell\SiteMonitor\Contracts\SiteMonitorHttpClient $backend): void
{
    app()->bind(SiteMonitorHttpClientAdapter::class, static fn (): SiteMonitorHttpClientAdapter => new SiteMonitorHttpClientAdapter($backend));
    app()->bind(\Capell\SiteMonitor\Contracts\SiteMonitorHttpClient::class, SiteMonitorHttpClientAdapter::class);
}
```

## Action `buildSiteMonitorDashboard`

<!-- example: action buildSiteMonitorDashboard -->

```php
<?php
declare(strict_types=1);

namespace App\CapellExamples\SiteMonitor;

function runBuildSiteMonitorDashboard(): \Capell\SiteMonitor\Data\SiteMonitorDashboardData
{
    return \Capell\SiteMonitor\Actions\BuildSiteMonitorDashboardAction::run();
}
```

## Action `reconcileSiteMonitorIncident`

<!-- example: action reconcileSiteMonitorIncident -->

```php
<?php
declare(strict_types=1);

namespace App\CapellExamples\SiteMonitor;

function runReconcileSiteMonitorIncident(\Capell\SiteMonitor\Models\SiteMonitorTarget $target, \Capell\SiteMonitor\Models\SiteMonitorRun $run): ?\Capell\SiteMonitor\Models\SiteMonitorIncident
{
    return \Capell\SiteMonitor\Actions\ReconcileSiteMonitorIncidentAction::run($target, $run);
}
```

## Action `recordSiteMonitorRun`

<!-- example: action recordSiteMonitorRun -->

```php
<?php
declare(strict_types=1);

namespace App\CapellExamples\SiteMonitor;

function runRecordSiteMonitorRun(\Capell\SiteMonitor\Models\SiteMonitorTarget $target, \Capell\SiteMonitor\Data\SiteMonitorCheckResultData $result): \Capell\SiteMonitor\Models\SiteMonitorRun
{
    return \Capell\SiteMonitor\Actions\RecordSiteMonitorRunAction::run($target, $result);
}
```

## Action `resolveSiteMonitorTargets`

<!-- example: action resolveSiteMonitorTargets -->

```php
<?php
declare(strict_types=1);

namespace App\CapellExamples\SiteMonitor;

function runResolveSiteMonitorTargets(?int $siteId = null, ?int $targetId = null): \Illuminate\Support\Collection
{
    return \Capell\SiteMonitor\Actions\ResolveSiteMonitorTargetsAction::run($siteId, $targetId);
}
```

## Action `runDueSiteMonitorChecks`

<!-- example: action runDueSiteMonitorChecks -->

```php
<?php
declare(strict_types=1);

namespace App\CapellExamples\SiteMonitor;

function runRunDueSiteMonitorChecks(bool $queue = true, ?int $siteId = null, ?int $targetId = null): int
{
    return \Capell\SiteMonitor\Actions\RunDueSiteMonitorChecksAction::run($queue, $siteId, $targetId);
}
```

## Action `runSiteMonitorCheck`

<!-- example: action runSiteMonitorCheck -->

```php
<?php
declare(strict_types=1);

namespace App\CapellExamples\SiteMonitor;

function runRunSiteMonitorCheck(\Capell\SiteMonitor\Models\SiteMonitorTarget $target): \Capell\SiteMonitor\Data\SiteMonitorCheckResultData
{
    return \Capell\SiteMonitor\Actions\RunSiteMonitorCheckAction::run($target);
}
```
