<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('layaways', function (Blueprint $table): void {
            $table->foreignId('created_by_user_id')->nullable()->after('catalog_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('terms_declared_by_user_id')->nullable()->after('terms_ip_address')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('terms_declared_by_user_id');
        });

        Schema::table('layaways', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_user_id');
        });
    }
};
