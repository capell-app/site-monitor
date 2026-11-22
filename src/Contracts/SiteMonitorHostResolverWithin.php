<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Contracts;

use Capell\SiteMonitor\Support\SiteMonitorDeadline;
use Illuminate\Http\Client\ConnectionException;

/**
 * A site monitor host resolver that gives up at the check's deadline.
 *
 * The system resolver takes no timeout, so a stalled DNS server could hold a
 * check past its own timeout and, across redirects, past the queue job's.
 */
interface SiteMonitorHostResolverWithin extends SiteMonitorHostResolver
{
    /**
     * @return list<string> the host's addresses; empty when it does not resolve
     *
     * @throws ConnectionException when the deadline passes before the host resolves
     */
    public function addressesWithin(string $host, SiteMonitorDeadline $deadline): array;
}
