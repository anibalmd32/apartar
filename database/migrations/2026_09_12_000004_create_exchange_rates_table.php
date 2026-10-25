<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();

            $table->string('source', 20);

            $table->decimal('rate', 18, 8);

            $table->timestamp('published_at');

            $table->timestamp('fetched_at')->useCurrent();

            $table->timestamps();

            $table->index(['source', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
