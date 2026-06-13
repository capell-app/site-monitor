<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_monitor_incidents')) {
            return;
        }

        Schema::create('site_monitor_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('target_id')->constrained('site_monitor_targets')->cascadeOnDelete();
            $table->foreignId('latest_run_id')->nullable()->constrained('site_monitor_runs')->nullOnDelete();
            $table->string('status')->index();
            $table->string('severity')->default('warning')->index();
            $table->string('summary');
            $table->unsignedInteger('failure_count')->default(1);
            $table->timestamp('opened_at')->index();
            $table->timestamp('last_failure_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_monitor_incidents');
    }
};
