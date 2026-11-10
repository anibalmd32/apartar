<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogs', function (Blueprint $table): void {
            $table->string('rate_source', 20)->nullable()->after('is_listed');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('rate_source', 20)->nullable()->after('frequency_days');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('rate_source');
        });

        Schema::table('catalogs', function (Blueprint $table): void {
            $table->dropColumn('rate_source');
        });
    }
};
