<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_digest_dispatches', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('catalog_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            $table->date('digest_date');

            $table->unsignedInteger('products_count');
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->unique(['catalog_id', 'account_id', 'digest_date'], 'catalog_digest_dispatches_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_digest_dispatches');
    }
};
