<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('customer_unread_count')->default(0);
            $table->unsignedInteger('admin_unread_count')->default(0);

            $table->timestamps();

            $table->unique(['organization_id', 'customer_id']);
            $table->index(['organization_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
