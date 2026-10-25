<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_subscriptions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_id')->constrained()->cascadeOnDelete();

            $table->timestamp('subscribed_at');
            $table->timestamp('muted_at')->nullable();

            $table->timestamps();

            $table->unique(['account_id', 'catalog_id']);

            $table->index('catalog_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_subscriptions');
    }
};
