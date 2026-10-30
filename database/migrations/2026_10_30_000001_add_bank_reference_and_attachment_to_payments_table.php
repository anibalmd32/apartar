<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BANK_CHECK = 'payments_bank_with_reference';

    private const FILE_CHECK = 'payments_file_complete';

    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('bank', 100)->nullable()->after('paid_on');
            $table->foreignId('bank_id')->nullable()->after('bank')->constrained('banks')->restrictOnDelete();
            $table->string('reference', 100)->nullable()->after('bank_id');

            $table->string('file_path')->nullable()->after('reference');
            $table->char('file_hash', 64)->nullable()->after('file_path');
            $table->string('file_mime', 100)->nullable()->after('file_hash');
            $table->unsignedInteger('file_size')->nullable()->after('file_mime');

            $table->unique(['organization_id', 'bank', 'reference']);
            $table->unique(['organization_id', 'file_hash']);
        });

        DB::statement(
            'UPDATE payments p JOIN receipts r ON r.id = p.receipt_id '
            .'SET p.bank = r.bank, p.bank_id = r.bank_id, p.reference = r.reference'
        );

        DB::statement('ALTER TABLE payments ADD CONSTRAINT '.self::BANK_CHECK.' CHECK ((bank IS NULL) = (reference IS NULL))');
        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT '.self::FILE_CHECK.' CHECK ('
            .'(file_path IS NULL) = (file_hash IS NULL) AND (file_path IS NULL) = (file_mime IS NULL) AND (file_path IS NULL) = (file_size IS NULL))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CHECK '.self::FILE_CHECK);
        DB::statement('ALTER TABLE payments DROP CHECK '.self::BANK_CHECK);

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'file_hash']);
            $table->dropUnique(['organization_id', 'bank', 'reference']);
            $table->dropConstrainedForeignId('bank_id');
            $table->dropColumn(['bank', 'reference', 'file_path', 'file_hash', 'file_mime', 'file_size']);
        });
    }
};
