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
        if (TableName::existsAndIsOwned('site_monitor_runs', basename(__FILE__, '.php'))) {
            return;
        }

        Schema::create(TableName::resolve('site_monitor_runs'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('target_id')->constrained(TableName::resolve('site_monitor_targets'))->cascadeOnDelete();
            $table->string('state')->index();
            $table->unsignedSmallInteger('status_code')->nullable()->index();
            $table->unsignedInteger('response_ms')->nullable();
            $table->dateTime('expires_at')->nullable()->index();
            $table->string('error_type')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->json('redirect_chain')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('checked_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('site_monitor_runs'));
    }
};
