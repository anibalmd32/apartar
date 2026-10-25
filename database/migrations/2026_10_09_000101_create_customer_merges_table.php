<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_merges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('absorbed_customer_id');
            $table->string('absorbed_document_number', 20);
            $table->foreignId('survivor_customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'absorbed_customer_id']);
            $table->index(['organization_id', 'absorbed_document_number']);
            $table->index(['organization_id', 'survivor_customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_merges');
    }
};
