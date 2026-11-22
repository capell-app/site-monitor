<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Notifications;

use Capell\SiteMonitor\Data\SiteMonitorIncidentAlertData;
use Capell\SiteMonitor\Data\SiteMonitorSlackMessageData;
use Capell\SiteMonitor\Notifications\Channels\SiteMonitorSlackChannel;
use Capell\SiteMonitor\Notifications\Channels\SiteMonitorWebhookChannel;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SiteMonitorIncidentNotification extends Notification
{
    public function __construct(private readonly SiteMonitorIncidentAlertData $alert) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof AnonymousNotifiable) {
            return ['mail'];
        }

        return array_values(array_filter(
            ['mail', SiteMonitorSlackChannel::class, SiteMonitorWebhookChannel::class],
            static fn (string $channel): bool => $notifiable->routeNotificationFor($channel) !== null,
        ));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $replace = [
            'name' => $this->alert->targetName,
            'url' => $this->alert->targetUrl,
            'failures' => $this->alert->failureCount,
            'changes' => $this->alert->failureCount,
            'status' => $this->alert->statusCode === null ? '-' : (string) $this->alert->statusCode,
            'error' => $this->alert->errorType ?? '-',
        ];

        if ($this->alert->event === SiteMonitorIncidentAlertData::EVENT_FLAPPING) {
            return (new MailMessage)
                ->subject((string) __('capell-site-monitor::package.alerts.flapping.subject', $replace))
                ->line((string) __('capell-site-monitor::package.alerts.flapping.body', $replace))
                ->line((string) __('capell-site-monitor::package.alerts.flapping.next_step'));
        }

        if ($this->alert->event === SiteMonitorIncidentAlertData::EVENT_RESOLVED) {
            return (new MailMessage)
                ->subject((string) __('capell-site-monitor::package.alerts.recovered.subject', $replace))
                ->line((string) __('capell-site-monitor::package.alerts.recovered.body', $replace))
                ->line((string) __('capell-site-monitor::package.alerts.recovered.next_step'));
        }

        return (new MailMessage)
            ->subject((string) __('capell-site-monitor::package.alerts.opened.subject', $replace))
            ->line((string) __('capell-site-monitor::package.alerts.opened.body', $replace))
            ->line((string) __('capell-site-monitor::package.alerts.opened.next_step'));
    }

    public function toWebhook(): SiteMonitorIncidentAlertData
    {
        return $this->alert;
    }

    public function toSlack(object $notifiable): SiteMonitorSlackMessageData
    {
        $mail = $this->toMail($notifiable);
        $text = $mail->subject . "\n" . implode("\n", $mail->introLines);

        return new SiteMonitorSlackMessageData(strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']));
    }
}
