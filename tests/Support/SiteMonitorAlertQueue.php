<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

function useSiteMonitorAlertQueue(): void
{
    // Keep queue table creation separate from the monitor's database transactions.
    config()->set('database.connections.site-monitor-alerts', ['driver' => 'sqlite', 'database' => ':memory:']);
    Schema::connection('site-monitor-alerts')->create('jobs', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    config()->set('queue.default', 'site-monitor-alerts');
    config()->set('queue.connections.site-monitor-alerts', [
        'driver' => 'database',
        'connection' => 'site-monitor-alerts',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => false,
    ]);
}

function workSiteMonitorAlertQueue(): void
{
    /** @var Worker $worker */
    $worker = app('queue.worker');
    $pending = Queue::connection('site-monitor-alerts')->size();

    for ($index = 0; $index < $pending; $index++) {
        $worker->runNextJob('site-monitor-alerts', 'default', new WorkerOptions(sleep: 0));
    }
}
