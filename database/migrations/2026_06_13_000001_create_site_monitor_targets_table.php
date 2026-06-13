<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_monitor_targets')) {
            return;
        }

        Schema::create('site_monitor_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->foreignId('language_id')->nullable()->index();
            $table->string('name');
            $table->string('url', 2048);
            $table->string('check_type')->index();
            $table->string('source_package')->nullable()->index();
            $table->string('source_key')->nullable()->index();
            $table->string('route_name')->nullable()->index();
            $table->unsignedSmallInteger('interval_minutes')->default(5);
            $table->unsignedInteger('timeout_ms')->default(5000);
            $table->unsignedTinyInteger('failure_threshold')->default(2);
            $table->unsignedSmallInteger('expected_status_minimum')->default(200);
            $table->unsignedSmallInteger('expected_status_maximum')->default(399);
            $table->boolean('enabled')->default(true)->index();
            $table->string('current_state')->default('unknown')->index();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('last_checked_at')->nullable()->index();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_monitor_targets');
    }
};
