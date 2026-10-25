<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_product', function (Blueprint $table): void {
            $table->foreignId('catalog_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('position')->default(0);

            $table->timestamp('created_at')->useCurrent();

            $table->primary(['catalog_id', 'product_id']);

            $table->index(['catalog_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product');
    }
};
