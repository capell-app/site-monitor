<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Notifications\Channels;

use Capell\SiteMonitor\Actions\DeliverSiteMonitorIncidentWebhookAction;
use Capell\SiteMonitor\Enums\SiteMonitorAlertChannel;
use Capell\SiteMonitor\Notifications\SiteMonitorIncidentNotification;
use Illuminate\Notifications\AnonymousNotifiable;

final class SiteMonitorWebhookChannel
{
    public function send(AnonymousNotifiable $notifiable, SiteMonitorIncidentNotification $notification): void
    {
        DeliverSiteMonitorIncidentWebhookAction::run(SiteMonitorAlertChannel::Webhook, $notification->toWebhook());
    }
}
