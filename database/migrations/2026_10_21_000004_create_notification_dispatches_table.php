<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_dispatches', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('notifiable_type', 64);
            $table->unsignedBigInteger('notifiable_id');
            $table->foreignId('installment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('layaway_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->string('channel', 16);
            $table->string('dedupe_key', 80);
            $table->timestamp('sent_at');

            $table->timestamps();

            $table->unique(['organization_id', 'dedupe_key', 'channel'], 'notification_dispatches_unique');
            $table->index(['organization_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatches');
    }
};
