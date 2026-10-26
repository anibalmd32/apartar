<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('courtesy_until')->nullable()->after('downgrade_applied_at');
            $table->timestamp('courtesy_previous_period_end')->nullable()->after('courtesy_until');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['courtesy_until', 'courtesy_previous_period_end']);
        });
    }
};
