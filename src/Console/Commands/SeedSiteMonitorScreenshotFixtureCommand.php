<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Console\Commands;

use Capell\SiteMonitor\Actions\Fixtures\PrepareSiteMonitorScreenshotAction;
use Illuminate\Console\Command;
use Throwable;

final class SeedSiteMonitorScreenshotFixtureCommand extends Command
{
    protected $signature = 'capell:site-monitor-screenshot-fixture {--force : Confirm an intentional disposable screenshot seed}';

    protected $description = 'Seed a disabled demo target and open incident for an explicit disposable screenshot run';

    public function handle(PrepareSiteMonitorScreenshotAction $action): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to seed screenshot fixtures without --force.');

            return self::FAILURE;
        }

        try {
            $target = $action->handle();
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Site Monitor screenshot fixture initialized target %s.', $target->name));

        return self::SUCCESS;
    }
}
