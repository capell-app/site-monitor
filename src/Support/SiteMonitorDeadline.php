<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

use Illuminate\Http\Client\ConnectionException;

final class SiteMonitorDeadline
{
    private readonly float $endsAt;

    private readonly SiteMonitorClock $clock;

    public function __construct(int $timeoutMs)
    {
        $this->clock = resolve(SiteMonitorClock::class);
        $this->endsAt = $this->clock->now() + SiteMonitorTimeout::effectiveMs($timeoutMs) / 1000;
    }

    public function remainingSeconds(): float
    {
        $remaining = $this->endsAt - $this->clock->now();

        if ($remaining <= 0) {
            throw new ConnectionException('The site monitor network deadline was exceeded.');
        }

        return $remaining;
    }

    public function throwIfExpired(): void
    {
        $this->remainingSeconds();
    }
}
