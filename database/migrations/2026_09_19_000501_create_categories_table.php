<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);

            $table->string('slug', 120);

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
            $table->unique(['organization_id', 'name']);

            $table->index(['organization_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
