<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

/**
 * Keeps a target's own timeout inside the queue job's timeout.
 *
 * The timeout applies to every request in a redirect chain, so the longest check is
 * (maximum redirects + 1) times the target timeout. A worker killed by the queue leaves
 * no recorded run and the target simply looks silent.
 */
final class SiteMonitorTimeout
{
    /** Time one target check may spend on the network, leaving headroom below the 60 second job timeout. */
    public const int JOB_BUDGET_MS = 50000;

    public const int MINIMUM_MS = 100;

    public static function maximumMs(): int
    {
        $redirects = config('capell-site-monitor.max_redirects', 5);
        $redirects = is_int($redirects) ? $redirects : (is_string($redirects) && is_numeric($redirects) ? (int) $redirects : 5);

        return max(self::MINIMUM_MS, intdiv(self::JOB_BUDGET_MS, max(0, $redirects) + 1));
    }

    public static function effectiveMs(int $timeoutMs): int
    {
        return max(self::MINIMUM_MS, min($timeoutMs, self::maximumMs()));
    }

    public static function seconds(int $timeoutMs): float
    {
        return max(1.0, self::effectiveMs($timeoutMs) / 1000);
    }
}
