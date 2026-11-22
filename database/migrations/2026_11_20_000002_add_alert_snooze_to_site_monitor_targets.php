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

        if (Schema::hasColumn($table, 'alerts_snoozed_until')) {
            return;
        }

        Schema::table($table, function (Blueprint $table): void {
            $table->dateTime('alerts_snoozed_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(TableName::resolve('site_monitor_targets'), function (Blueprint $table): void {
            $table->dropColumn('alerts_snoozed_until');
        });
    }
};
