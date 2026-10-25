<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 160);

            $table->string('slug', 100);

            $table->text('description')->nullable();
            $table->string('cover_path')->nullable();

            $table->boolean('is_listed')->default(true);

            $table->timestamp('published_at')->nullable();

            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->unique('slug');

            $table->index(['organization_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogs');
    }
};
