<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Manifest;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;

final class SiteMonitorHealthContribution implements ChecksExtensionHealth
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^4.0';
    }
}
