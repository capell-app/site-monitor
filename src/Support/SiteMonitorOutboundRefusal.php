<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

use Capell\Core\Exceptions\OutboundRequestRefusedException;
use Capell\SiteMonitor\Exceptions\UnsafeSiteMonitorTargetException;

/**
 * Translates a refusal from Core's guarded HTTP client into the failure types the
 * site monitor already records, so results read the same whichever guard stopped
 * the request.
 */
final class SiteMonitorOutboundRefusal
{
    public static function toUnsafeTarget(OutboundRequestRefusedException $exception): UnsafeSiteMonitorTargetException
    {
        return match ($exception->reason) {
            OutboundRequestRefusedException::REASON_UNRESOLVED => new UnsafeSiteMonitorTargetException('target_host_unresolvable', 'Target host could not be resolved before the outbound check.'),
            OutboundRequestRefusedException::REASON_SCHEME => new UnsafeSiteMonitorTargetException('unsupported_target_scheme', 'Target URL must use HTTP or HTTPS.'),
            OutboundRequestRefusedException::REASON_CREDENTIALS => new UnsafeSiteMonitorTargetException('target_url_credentials', 'Target URL must not contain a user name or password.'),
            OutboundRequestRefusedException::REASON_SIZE => new UnsafeSiteMonitorTargetException('response_too_large', 'Target response exceeded the download limit.'),
            default => new UnsafeSiteMonitorTargetException('unsafe_target_address', 'Target host resolves to a private or reserved network address.'),
        };
    }
}
