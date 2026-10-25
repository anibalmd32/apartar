<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('tagline', 80)->nullable()->after('phone');
            $table->string('description', 300)->nullable()->after('tagline');
            $table->string('address', 160)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['tagline', 'description', 'address']);
        });
    }
};
