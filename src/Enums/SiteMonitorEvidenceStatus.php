<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Enums;

use Filament\Support\Contracts\HasLabel;
use Override;

enum SiteMonitorEvidenceStatus: string implements HasLabel
{
    case Paused = 'paused';
    case NeverChecked = 'never_checked';
    case Overdue = 'overdue';
    case Fresh = 'fresh';

    #[Override]
    public function getLabel(): string
    {
        return __('capell-site-monitor::package.evidence.' . $this->value);
    }
}
