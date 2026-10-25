<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_events', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('catalog_id')->nullable();
            $table->string('event_name', 40);
            $table->uuid('visitor_id');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('subject_type', 20)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('payload');
            $table->string('session_id', 40);
            $table->char('ip_hash', 64);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['event_name', 'occurred_at']);
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['catalog_id', 'occurred_at']);
            $table->index('account_id');
            $table->index('visitor_id');
            $table->index(['organization_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_events');
    }
};
