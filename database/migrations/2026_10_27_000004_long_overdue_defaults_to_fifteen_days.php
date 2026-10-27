<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('organization_settings')
            ->where('key', 'overdue_alert_days')
            ->whereRaw('`value` = CAST(30 AS JSON)')
            ->update(['value' => '15']);
    }

    public function down(): void
    {
        DB::table('organization_settings')
            ->where('key', 'overdue_alert_days')
            ->whereRaw('`value` = CAST(15 AS JSON)')
            ->update(['value' => '30']);
    }
};
