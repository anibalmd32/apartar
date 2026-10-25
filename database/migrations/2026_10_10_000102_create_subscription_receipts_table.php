<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();

            $table->string('payment_method', 30);
            $table->string('bank', 100)->nullable();
            $table->string('reference', 100)->nullable();
            $table->date('paid_at');
            $table->decimal('declared_amount', 16, 2);
            $table->char('declared_currency', 3);

            $table->string('file_path');
            $table->char('file_hash', 64);
            $table->string('file_mime', 100);
            $table->unsignedInteger('file_size');

            $table->string('status', 20)->default('pending_review');

            $table->decimal('settled_amount', 16, 2)->nullable();
            $table->char('settled_currency', 3)->nullable();
            $table->unsignedInteger('settled_cents_usd')->nullable();
            $table->decimal('rate_applied', 18, 8)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->timestamp('rate_published_at')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'file_hash']);
            $table->unique(['organization_id', 'bank', 'reference']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_receipts');
    }
};
