<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECK = 'payments_unallocated_within_amount';

    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->bigInteger('unallocated_cents')->after('amount_cents_usd');
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT '.self::CHECK.' CHECK (unallocated_cents >= 0 AND unallocated_cents <= amount_cents_usd)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CHECK '.self::CHECK);

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('unallocated_cents');
        });
    }
};
