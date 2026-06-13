<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_monitor_runs')) {
            return;
        }

        Schema::create('site_monitor_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('target_id')->constrained('site_monitor_targets')->cascadeOnDelete();
            $table->string('state')->index();
            $table->unsignedSmallInteger('status_code')->nullable()->index();
            $table->unsignedInteger('response_ms')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('error_type')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->json('redirect_chain')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('checked_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_monitor_runs');
    }
};
