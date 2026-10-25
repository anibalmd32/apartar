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
        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->foreignId('layaway_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_account_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('payment_method', 20);

            $table->string('bank', 100)->nullable();
            $table->string('reference', 100)->nullable();

            $table->date('paid_at');

            $table->decimal('declared_amount', 16, 2);
            $table->string('declared_currency', 3);

            $table->string('note', 500)->nullable();

            $table->string('file_path');
            $table->char('file_hash', 64);
            $table->string('file_mime', 100);
            $table->unsignedInteger('file_size');

            $table->string('status', 20)->default('pending_review');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->string('rejection_reason_code', 30)->nullable();
            $table->string('rejection_reason', 500)->nullable();

            $table->foreignId('replaces_receipt_id')->nullable()->constrained('receipts')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['organization_id', 'file_hash']);
            $table->unique(['organization_id', 'bank', 'reference']);

            $table->index(['organization_id', 'status', 'created_at']);

            $table->index(['organization_id', 'layaway_id', 'status']);
        });

        DB::statement('ALTER TABLE receipts ADD CONSTRAINT receipts_bank_with_reference CHECK ((bank IS NULL) = (reference IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
