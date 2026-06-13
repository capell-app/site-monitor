<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Data;

use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Spatie\LaravelData\Data;

final class SiteMonitorTargetData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly SiteMonitorCheckType $checkType,
        public readonly ?int $siteId = null,
        public readonly ?int $languageId = null,
        public readonly ?string $sourcePackage = null,
        public readonly ?string $sourceKey = null,
        public readonly ?string $routeName = null,
        public readonly int $intervalMinutes = 5,
        public readonly int $timeoutMs = 5000,
        public readonly int $failureThreshold = 2,
        public readonly int $expectedStatusMinimum = 200,
        public readonly int $expectedStatusMaximum = 399,
        public readonly bool $enabled = true,
    ) {}
}
