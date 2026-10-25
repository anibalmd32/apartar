<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            $table->foreignId('installment_id')->constrained()->restrictOnDelete();

            $table->bigInteger('amount_cents');

            $table->timestamps();

            $table->index(['organization_id', 'payment_id']);
            $table->index(['organization_id', 'installment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
