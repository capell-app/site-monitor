<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Manifest;

use Capell\Core\Contracts\Extensions\ExtensionContribution;

final class SiteMonitorDashboardPageContribution implements ExtensionContribution
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^4.0';
    }
}
