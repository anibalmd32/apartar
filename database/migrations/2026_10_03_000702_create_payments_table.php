<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('layaway_id')->constrained()->restrictOnDelete();
            $table->foreignId('receipt_id')->nullable()->constrained()->restrictOnDelete();

            $table->bigInteger('amount_cents_usd');

            $table->string('declared_currency', 3);
            $table->decimal('declared_amount', 16, 2);

            $table->decimal('rate_applied', 18, 8);

            $table->string('rate_source', 20);

            $table->timestamp('rate_published_at')->nullable();

            $table->timestamp('settled_at');

            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'receipt_id']);

            $table->index(['organization_id', 'settled_at']);

            $table->index(['organization_id', 'layaway_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
