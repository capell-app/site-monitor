<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (TableName::existsAndIsOwned('site_monitor_incidents', basename(__FILE__, '.php'))) {
            return;
        }

        Schema::create(TableName::resolve('site_monitor_incidents'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('target_id')->constrained(TableName::resolve('site_monitor_targets'))->cascadeOnDelete();
            $table->foreignId('latest_run_id')->nullable()->constrained(TableName::resolve('site_monitor_runs'))->nullOnDelete();
            $table->string('status')->index();
            $table->string('severity')->default('warning')->index();
            $table->string('summary');
            $table->unsignedInteger('failure_count')->default(1);
            $table->dateTime('opened_at')->index();
            $table->timestamp('last_failure_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('site_monitor_incidents'));
    }
};
