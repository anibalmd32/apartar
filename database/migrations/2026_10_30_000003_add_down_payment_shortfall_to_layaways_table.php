<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('layaways', function (Blueprint $table): void {
            $table->bigInteger('down_payment_shortfall_cents')->default(0)->after('down_payment_cents');
        });
    }

    public function down(): void
    {
        Schema::table('layaways', function (Blueprint $table): void {
            $table->dropColumn('down_payment_shortfall_cents');
        });
    }
};
