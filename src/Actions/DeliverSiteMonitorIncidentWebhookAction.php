<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\Core\Support\Http\OutboundHttpPolicy;
use Capell\SiteMonitor\Data\SiteMonitorIncidentAlertData;
use Capell\SiteMonitor\Data\SiteMonitorSlackMessageData;
use Capell\SiteMonitor\Enums\SiteMonitorAlertChannel;
use Capell\SiteMonitor\Exceptions\SiteMonitorHttpAlertDeliveryException;
use Capell\SiteMonitor\Support\SiteMonitorAlertHttpFactory;
use Illuminate\Http\Client\Response;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use SensitiveParameter;
use Throwable;

final class DeliverSiteMonitorIncidentWebhookAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly SiteMonitorAlertHttpFactory $http) {}

    public function handle(SiteMonitorAlertChannel $channel, SiteMonitorIncidentAlertData|SiteMonitorSlackMessageData $message): void
    {
        $url = $channel->endpointUrl();

        if ($url === null) {
            return;
        }

        $token = $channel === SiteMonitorAlertChannel::Webhook ? config('capell-site-monitor.alerts.webhook_token') : null;

        try {
            $response = $this->post($url, $message, is_string($token) ? $token : null);
        } catch (Throwable $exception) {
            // Laravel publishes the exception in NotificationFailed before the job can catch it.
            throw new SiteMonitorHttpAlertDeliveryException(sprintf('Site Monitor incident alert delivery failed for %s (%s).', $channel->value, $exception::class));
        }

        if (! $response->successful()) {
            throw new SiteMonitorHttpAlertDeliveryException(sprintf('Site Monitor incident alert delivery failed for %s (HTTP %d).', $channel->value, $response->status()));
        }
    }

    private function post(
        #[SensitiveParameter]
        string $url,
        SiteMonitorIncidentAlertData|SiteMonitorSlackMessageData $message,
        #[SensitiveParameter]
        ?string $token,
    ): Response {
        $configuredTimeout = config('capell-site-monitor.alerts.http_timeout_seconds', 5);
        $timeout = is_numeric($configuredTimeout) ? max(1, min(30, (int) $configuredTimeout)) : 5;
        $port = parse_url($url, PHP_URL_PORT);
        $request = $this->http->guarded(new OutboundHttpPolicy(
            schemes: ['https'],
            ports: [is_int($port) ? $port : 443],
            maxBytes: 64 * 1024,
            connectTimeoutSeconds: $timeout,
            timeoutSeconds: $timeout,
        ))->asJson()->withOptions(['decode_content' => false]);

        if ($token !== null && $token !== '') {
            $request->withToken($token);
        }

        return $request->post($url, $message->toArray());
    }
}
