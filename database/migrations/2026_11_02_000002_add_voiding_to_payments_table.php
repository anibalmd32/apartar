<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOID_CHECK = 'payments_void_complete';

    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestamp('voided_at')->nullable()->after('settled_at');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable()->after('voided_by');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_receipt_id')->nullable()->storedAs('IF(voided_at IS NULL, receipt_id, NULL)')->after('receipt_id');
            $table->string('active_reference', 100)->nullable()->storedAs('IF(voided_at IS NULL, reference, NULL)')->after('reference');
            $table->char('active_file_hash', 64)->nullable()->storedAs('IF(voided_at IS NULL, file_hash, NULL)')->after('file_hash');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'receipt_id']);
            $table->dropUnique(['organization_id', 'bank', 'reference']);
            $table->dropUnique(['organization_id', 'file_hash']);

            $table->unique(['organization_id', 'active_receipt_id']);
            $table->unique(['organization_id', 'bank', 'active_reference'], 'payments_organization_id_bank_reference_unique');
            $table->unique(['organization_id', 'active_file_hash'], 'payments_organization_id_file_hash_unique');
            $table->index(['organization_id', 'bank', 'reference']);
            $table->index(['organization_id', 'file_hash']);
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT '.self::VOID_CHECK.' CHECK ((voided_at IS NULL) = (void_reason IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CHECK '.self::VOID_CHECK);

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'file_hash']);
            $table->dropIndex(['organization_id', 'bank', 'reference']);
            $table->dropUnique('payments_organization_id_file_hash_unique');
            $table->dropUnique('payments_organization_id_bank_reference_unique');
            $table->dropUnique(['organization_id', 'active_receipt_id']);

            $table->unique(['organization_id', 'receipt_id']);
            $table->unique(['organization_id', 'bank', 'reference']);
            $table->unique(['organization_id', 'file_hash']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['active_receipt_id', 'active_reference', 'active_file_hash']);
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
