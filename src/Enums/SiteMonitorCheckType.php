<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Enums;

use Filament\Support\Contracts\HasLabel;

enum SiteMonitorCheckType: string implements HasLabel
{
    case HttpStatus = 'http_status';
    case SslCertificate = 'ssl_certificate';
    case DomainExpiry = 'domain_expiry';

    public function getLabel(): string
    {
        return __("capell-site-monitor::package.check_types.{$this->value}");
    }
}
