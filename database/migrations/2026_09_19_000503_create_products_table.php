<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('sku', 64);
            $table->string('name', 200);
            $table->string('slug', 200);
            $table->text('description')->nullable();

            $table->bigInteger('price_cents');
            $table->bigInteger('compare_at_price_cents')->nullable();

            $table->bigInteger('down_payment_cents')->default(0);
            $table->unsignedSmallInteger('installments_count')->default(1);
            $table->unsignedSmallInteger('frequency_days')->default(15);

            $table->unsignedInteger('stock')->default(0);

            $table->string('status', 20)->default('draft');

            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'sku']);
            $table->unique(['organization_id', 'slug']);

            $table->index(['organization_id', 'status']);

            $table->index(['organization_id', 'category_id']);

            $table->fullText(['name', 'description', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
