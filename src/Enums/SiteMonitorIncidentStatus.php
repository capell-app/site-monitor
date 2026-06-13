<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Enums;

use Filament\Support\Contracts\HasLabel;

enum SiteMonitorIncidentStatus: string implements HasLabel
{
    case Open = 'open';
    case Resolved = 'resolved';

    public function getLabel(): string
    {
        return __("capell-site-monitor::package.incident_statuses.{$this->value}");
    }
}
