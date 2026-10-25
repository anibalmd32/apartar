<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('metric', 32);

            $table->unsignedBigInteger('value')->default(0);

            $table->timestamp('computed_at');

            $table->timestamps();

            $table->unique(['organization_id', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
