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
        $table = TableName::resolve('site_monitor_targets');

        if (Schema::hasColumn($table, 'expected_keyword')) {
            return;
        }

        Schema::table($table, function (Blueprint $table): void {
            $table->string('expected_keyword')->nullable();
            $table->unsignedInteger('max_response_ms')->nullable();
            $table->dateTime('maintenance_starts_at')->nullable();
            $table->dateTime('maintenance_ends_at')->nullable();
            $table->boolean('is_flapping')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table(TableName::resolve('site_monitor_targets'), function (Blueprint $table): void {
            $table->dropColumn(['expected_keyword', 'max_response_ms', 'maintenance_starts_at', 'maintenance_ends_at', 'is_flapping']);
        });
    }
};
