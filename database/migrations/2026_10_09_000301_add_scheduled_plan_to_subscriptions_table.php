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
            $table->foreignId('scheduled_plan_id')->nullable()->after('plan_id')->constrained('plans')->restrictOnDelete();
            $table->timestamp('scheduled_plan_at')->nullable()->after('scheduled_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('scheduled_plan_id');
            $table->dropColumn('scheduled_plan_at');
        });
    }
};
