<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Listeners;

use Capell\SiteMonitor\Data\SiteMonitorIncidentAlertData;
use Capell\SiteMonitor\Enums\SiteMonitorAlertChannel;
use Capell\SiteMonitor\Events\SiteMonitorIncidentOpened;
use Capell\SiteMonitor\Events\SiteMonitorIncidentResolved;
use Capell\SiteMonitor\Events\SiteMonitorTargetFlapping;
use Capell\SiteMonitor\Jobs\DeliverSiteMonitorIncidentAlertJob;
use Illuminate\Queue\FailoverQueue;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class SendSiteMonitorIncidentAlertListener
{
    public function handle(SiteMonitorIncidentOpened|SiteMonitorIncidentResolved|SiteMonitorTargetFlapping $event): void
    {
        $recovered = $event instanceof SiteMonitorIncidentResolved;

        // The incident is still recorded; only the notification is held back. An incident whose
        // opening was snoozed is marked, so its recovery does not arrive as an unexplained message.
        if ($event->target->alertsAreSnoozed()) {
            if ($event instanceof SiteMonitorIncidentOpened) {
                $event->incident->forceFill(['metadata' => [...($event->incident->metadata ?? []), 'alert_snoozed' => true]])->save();
            }

            return;
        }

        if ($event instanceof SiteMonitorIncidentResolved && ($event->incident->metadata['alert_snoozed'] ?? false) === true) {
            return;
        }

        if ($recovered && ! (bool) config('capell-site-monitor.alerts.notify_on_recovery', true)) {
            return;
        }

        $channels = array_filter(SiteMonitorAlertChannel::cases(), static fn (SiteMonitorAlertChannel $channel): bool => $channel->route() !== null);

        if ($channels === []) {
            return;
        }

        $configuredConnection = config('capell-site-monitor.alerts.queue_connection');
        $connection = is_string($configuredConnection) && trim($configuredConnection) !== ''
            ? trim($configuredConnection)
            : Queue::getDefaultDriver();

        if (! $this->usesWorker($connection)) {
            Log::warning('Site Monitor incident alerts require a worker-backed queue connection.');

            return;
        }

        $alert = $event instanceof SiteMonitorTargetFlapping
            ? SiteMonitorIncidentAlertData::fromFlapping($event->target, $event->run, $event->changes)
            : SiteMonitorIncidentAlertData::fromIncident($event->incident, $event->target, $event->run, $recovered);

        foreach ($channels as $channel) {
            try {
                Bus::dispatch(new DeliverSiteMonitorIncidentAlertJob($channel, $alert)->onConnection($connection));
            } catch (Throwable $exception) {
                Log::warning('Site Monitor incident alert could not be queued.', [
                    'channel' => $channel->value,
                    'exception_type' => $exception::class,
                ]);
            }
        }
    }

    /** @param list<string> $visited */
    private function usesWorker(string $name, array $visited = []): bool
    {
        if (in_array($name, $visited, true)) {
            return false;
        }

        try {
            $queue = Queue::connection($name);
        } catch (Throwable) {
            return false;
        }

        if (! $queue instanceof FailoverQueue) {
            return ! $queue instanceof SyncQueue && ! $queue instanceof NullQueue;
        }

        foreach ($queue->connections as $fallback) {
            if (! is_string($fallback) || ! $this->usesWorker($fallback, [...$visited, $name])) {
                return false;
            }
        }

        return $queue->connections !== [];
    }
}
