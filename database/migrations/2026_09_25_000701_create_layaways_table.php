<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('layaways', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('code', 16);

            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->foreignId('catalog_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('quantity');

            $table->bigInteger('unit_price_cents');
            $table->bigInteger('down_payment_cents');

            $table->unsignedSmallInteger('installments_count');
            $table->unsignedSmallInteger('frequency_days');

            $table->string('product_name_snapshot', 200);
            $table->string('product_sku_snapshot', 64);

            $table->bigInteger('total_cents');

            $table->bigInteger('paid_cents')->default(0);

            $table->bigInteger('remaining_cents');

            $table->string('status', 30)->default('pending_down_payment');

            $table->timestamp('expires_at')->nullable();

            $table->timestamp('extended_at')->nullable();

            $table->timestamp('activated_at')->nullable();

            $table->date('next_due_date')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->string('cancellation_reason', 500)->nullable();

            $table->timestamp('delivered_at')->nullable();

            $table->timestamp('stock_released_at')->nullable();

            $table->timestamps();

            $table->unique('code');

            $table->index(['organization_id', 'status', 'next_due_date']);

            $table->index(['organization_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('layaways');
    }
};
