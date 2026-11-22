<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

use Capell\Core\Support\Http\OutboundHttp;
use Capell\Core\Support\Http\OutboundHttpPolicy;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class SiteMonitorAlertHttpFactory
{
    public readonly Factory $client;

    public function __construct()
    {
        // Building the client directly keeps container hooks on the shared Factory type off it.
        $this->client = new Factory;
    }

    public function guarded(OutboundHttpPolicy $policy): PendingRequest
    {
        // Core builds through the facade. Scope the swap to request construction so the
        // guarded request uses this factory without the application's event dispatcher or middleware.
        $original = Http::getFacadeRoot();
        Http::swap($this->client);

        try {
            return OutboundHttp::guarded($policy);
        } finally {
            Http::swap($original);
        }
    }
}
