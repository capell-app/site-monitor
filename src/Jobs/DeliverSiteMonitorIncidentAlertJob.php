<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Jobs;

use Capell\SiteMonitor\Data\SiteMonitorIncidentAlertData;
use Capell\SiteMonitor\Enums\SiteMonitorAlertChannel;
use Capell\SiteMonitor\Exceptions\SiteMonitorHttpAlertDeliveryException;
use Capell\SiteMonitor\Notifications\SiteMonitorIncidentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

final class DeliverSiteMonitorIncidentAlertJob implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly SiteMonitorAlertChannel $channel,
        public readonly SiteMonitorIncidentAlertData $alert,
    ) {}

    public function handle(): void
    {
        $route = $this->channel->route();

        if ($route === null) {
            return;
        }

        try {
            Notification::route($this->channel->notificationChannel(), $route)
                ->notify(new SiteMonitorIncidentNotification($this->alert));
        } catch (SiteMonitorHttpAlertDeliveryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // The original exception can carry request URLs, headers and response bodies.
            throw new RuntimeException(sprintf('Site Monitor incident alert delivery failed for %s (%s).', $this->channel->value, $exception::class));
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Queue timeouts are reported via this hook before the worker exits.
        if ($exception instanceof TimeoutExceededException) {
            report(new RuntimeException(sprintf('Site Monitor incident alert delivery timed out for %s.', $this->channel->value)));
        }
    }
}
