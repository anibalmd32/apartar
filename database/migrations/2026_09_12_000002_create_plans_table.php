<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();

            $table->string('code', 32);
            $table->string('name');

            $table->bigInteger('price_cents')->default(0);
            $table->string('interval', 20)->default('monthly');

            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_catalogs')->nullable();
            $table->unsignedInteger('max_products')->nullable();
            $table->unsignedInteger('max_products_per_catalog')->nullable();
            $table->unsignedInteger('max_active_layaways')->nullable();
            $table->unsignedInteger('max_storage_mb')->nullable();
            $table->unsignedInteger('max_payment_accounts')->nullable();

            $table->json('features')->nullable();

            $table->timestamps();

            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
