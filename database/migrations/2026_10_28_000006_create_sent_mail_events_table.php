<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_mail_events', function (Blueprint $table): void {
            $table->id();

            $table->string('event_id', 100)->unique();
            $table->foreignId('sent_mail_id')->nullable()->constrained('sent_mails')->cascadeOnDelete();
            $table->string('mailtrap_message_id', 100)->default('');
            $table->string('type', 32);
            $table->string('email', 255)->default('');
            $table->timestamp('occurred_at');
            $table->string('url', 2048)->nullable();
            $table->text('reason')->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('bounce_category', 100)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('payload');
            $table->timestamp('created_at');

            $table->index(['sent_mail_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
            $table->index('mailtrap_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_mail_events');
    }
};
