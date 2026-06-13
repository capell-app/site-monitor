<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Console\Commands;

use Capell\SiteMonitor\Actions\RunDueSiteMonitorChecksAction;
use Illuminate\Console\Command;
use Override;

final class RunSiteMonitorCommand extends Command
{
    protected $signature = 'capell:site-monitor:run
        {--sync : Run checks inline instead of dispatching queue jobs}
        {--site-id= : Limit checks to a site id}
        {--target-id= : Limit checks to a single monitor target id}';

    protected $description = 'Run due Site Monitor checks.';

    #[Override]
    public function getDescription(): string
    {
        return (string) __('capell-site-monitor::package.commands.run.description');
    }

    public function handle(): int
    {
        $count = (new RunDueSiteMonitorChecksAction)->handle(
            queue: ! (bool) $this->option('sync'),
            siteId: $this->integerOption('site-id'),
            targetId: $this->integerOption('target-id'),
        );

        $this->components->info((string) trans_choice(
            'capell-site-monitor::package.commands.run.completed',
            $count,
            ['count' => $count],
        ));

        return self::SUCCESS;
    }

    private function integerOption(string $name): ?int
    {
        $value = $this->option($name);

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
