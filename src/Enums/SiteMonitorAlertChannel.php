<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Enums;

use Capell\SiteMonitor\Notifications\Channels\SiteMonitorSlackChannel;
use Capell\SiteMonitor\Notifications\Channels\SiteMonitorWebhookChannel;

enum SiteMonitorAlertChannel: string
{
    case Mail = 'mail';
    case Slack = 'slack';
    case Webhook = 'webhook';

    public function notificationChannel(): string
    {
        return match ($this) {
            self::Mail => 'mail',
            self::Slack => SiteMonitorSlackChannel::class,
            self::Webhook => SiteMonitorWebhookChannel::class,
        };
    }

    /** @return non-empty-list<string>|true|null */
    public function route(): array|true|null
    {
        if ($this === self::Mail) {
            $recipients = $this->recipients();

            return $recipients === [] ? null : $recipients;
        }

        // Laravel includes this route in notification events. HTTP channels need only a marker.
        return $this->endpointUrl() === null ? null : true;
    }

    /** @return non-empty-string|null */
    public function endpointUrl(): ?string
    {
        $key = match ($this) {
            self::Slack => 'capell-site-monitor.alerts.slack_url',
            self::Webhook => 'capell-site-monitor.alerts.webhook_url',
            self::Mail => null,
        };
        $url = $key === null ? null : config($key);
        $url = is_string($url) ? trim($url) : '';

        return $url === '' ? null : $url;
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $configured = config('capell-site-monitor.alerts.mail_to', []);

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $address): string => is_string($address) ? trim($address) : '', $configured),
            static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }
}
