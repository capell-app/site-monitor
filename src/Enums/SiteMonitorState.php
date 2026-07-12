<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Enums;

use Filament\Support\Contracts\HasLabel;

enum SiteMonitorState: string implements HasLabel
{
    case Unknown = 'unknown';
    case Passing = 'passing';
    case Warning = 'warning';
    case Failing = 'failing';

    public function getLabel(): string
    {
        return __("capell-site-monitor::package.states.{$this->value}");
    }
}
