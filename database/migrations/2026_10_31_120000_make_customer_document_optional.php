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
            $table->string('document_number', 16)->nullable()->change();
            $table->string('document_kind', 10)->nullable()->change();
        });

        Schema::table('customer_merges', function (Blueprint $table): void {
            $table->string('absorbed_document_number', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customer_merges', function (Blueprint $table): void {
            $table->string('absorbed_document_number', 20)->nullable(false)->change();
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('document_kind', 10)->nullable(false)->change();
            $table->string('document_number', 16)->nullable(false)->change();
        });
    }
};
