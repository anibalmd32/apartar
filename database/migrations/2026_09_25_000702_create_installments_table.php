<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->foreignId('layaway_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('number');

            $table->date('due_date');

            $table->bigInteger('amount_cents');
            $table->bigInteger('paid_cents')->default(0);

            $table->string('status', 20)->default('pending');

            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'layaway_id', 'number']);

            $table->index(['organization_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};
