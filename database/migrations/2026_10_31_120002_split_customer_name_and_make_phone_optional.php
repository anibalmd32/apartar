<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('first_name', 200)->nullable()->after('full_name');
            $table->string('last_name', 200)->nullable()->after('first_name');
            $table->string('phone', 16)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('phone', 16)->nullable(false)->change();
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
