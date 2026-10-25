<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('payment_method', 20)->nullable()->after('receipt_id');
            $table->foreignId('payment_account_id')->nullable()->after('payment_method')->constrained()->restrictOnDelete();
            $table->date('paid_on')->nullable()->after('payment_account_id');
        });

        DB::statement(
            'UPDATE payments p JOIN receipts r ON r.id = p.receipt_id '
            .'SET p.payment_method = r.payment_method, p.payment_account_id = r.payment_account_id, p.paid_on = r.paid_at'
        );
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_account_id');
            $table->dropColumn(['payment_method', 'paid_on']);
        });
    }
};
