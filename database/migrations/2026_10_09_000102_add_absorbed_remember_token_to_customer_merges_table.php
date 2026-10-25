<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_merges', function (Blueprint $table): void {
            $table->string('absorbed_remember_token', 100)->nullable()->after('absorbed_document_number');
        });
    }

    public function down(): void
    {
        Schema::table('customer_merges', function (Blueprint $table): void {
            $table->dropColumn('absorbed_remember_token');
        });
    }
};
