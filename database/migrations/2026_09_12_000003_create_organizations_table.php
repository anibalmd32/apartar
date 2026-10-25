<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');

            $table->string('slug', 100);

            $table->string('document', 32)->nullable();

            $table->string('timezone', 64)->default('America/Caracas');

            $table->string('logo_path')->nullable();
            $table->string('brand_color', 7)->nullable();

            $table->boolean('storefront_enabled')->default(false);

            $table->string('status', 20)->default('active');

            $table->timestamp('suspended_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('slug');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
