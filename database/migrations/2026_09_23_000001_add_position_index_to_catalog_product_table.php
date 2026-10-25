<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_product', function (Blueprint $table): void {
            $table->index(['catalog_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_product', function (Blueprint $table): void {
            $table->dropIndex(['catalog_id', 'position']);
        });
    }
};
