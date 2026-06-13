<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Filament\Pages;

use BackedEnum;
use Capell\Core\Facades\CapellCore;
use Capell\SiteMonitor\Actions\BuildSiteMonitorDashboardAction;
use Capell\SiteMonitor\Actions\RunDueSiteMonitorChecksAction;
use Capell\SiteMonitor\Data\SiteMonitorDashboardData;
use Capell\SiteMonitor\Providers\SiteMonitorServiceProvider;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Override;

final class SiteMonitorDashboardPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Signal;

    protected static ?string $slug = 'site-monitor';

    protected static ?int $navigationSort = 18;

    protected string $view = 'capell-site-monitor::filament.pages.site-monitor-dashboard';

    private ?SiteMonitorDashboardData $cachedDashboard = null;

    #[Override]
    public static function getNavigationLabel(): string
    {
        return __('capell-site-monitor::package.navigation.dashboard');
    }

    #[Override]
    public static function getNavigationGroup(): string
    {
        return __('capell-admin::navigation.group_monitoring');
    }

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return CapellCore::isPackageInstalled(SiteMonitorServiceProvider::$packageName);
    }

    #[Override]
    public function getTitle(): string
    {
        return __('capell-site-monitor::package.navigation.dashboard');
    }

    #[Override]
    public function getSubheading(): string
    {
        return __('capell-site-monitor::package.dashboard.subheading');
    }

    public function dashboard(): SiteMonitorDashboardData
    {
        return $this->cachedDashboard ??= (new BuildSiteMonitorDashboardAction)->handle();
    }

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('run_due_checks')
                ->label(__('capell-site-monitor::package.actions.run_due_checks'))
                ->icon('heroicon-o-play')
                ->action(function (): void {
                    $count = (new RunDueSiteMonitorChecksAction)->handle(queue: true);

                    Notification::make('capell_site_monitor_checks_queued')
                        ->title(trans_choice(
                            'capell-site-monitor::package.notifications.checks_queued',
                            $count,
                            ['count' => $count],
                        ))
                        ->success()
                        ->send();
                }),
        ];
    }
}
