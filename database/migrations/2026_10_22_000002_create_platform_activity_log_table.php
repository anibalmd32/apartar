<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_activity_log', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('platform_user_id')->nullable();
            $table->unsignedBigInteger('target_organization_id')->nullable();
            $table->string('action', 64);
            $table->string('description', 500);
            $table->json('properties');
            $table->timestamp('created_at');

            $table->index(['target_organization_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_activity_log');
    }
};
