<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Tests\Fixtures;

use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Models\SiteMonitorTarget;

final class FakeSiteMonitorHttpClient implements SiteMonitorHttpClient
{
    public function __construct(
        private SiteMonitorCheckResultData $result,
    ) {}

    public function check(SiteMonitorTarget $target): SiteMonitorCheckResultData
    {
        return $this->result;
    }

    public function replaceResult(SiteMonitorCheckResultData $result): void
    {
        $this->result = $result;
    }
}
