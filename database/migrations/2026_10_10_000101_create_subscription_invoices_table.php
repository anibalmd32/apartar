<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();

            $table->string('kind', 20);

            $table->timestamp('period_start');
            $table->timestamp('period_end');

            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('paid_cents')->default(0);
            $table->char('currency', 3)->default('USD');

            $table->string('status', 20)->default('pending');
            $table->date('due_date');
            $table->timestamp('issued_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'due_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
