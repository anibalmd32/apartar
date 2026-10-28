<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_threads', function (Blueprint $table): void {
            $table->id();

            $table->string('mailtrap_thread_id', 100)->nullable()->unique();
            $table->string('subject', 500);
            $table->string('counterpart', 255);
            $table->string('snippet', 300)->default('');
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at');

            $table->timestamps();

            $table->index('last_message_at');
            $table->index(['unread_count', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_threads');
    }
};
