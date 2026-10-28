<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_mails', function (Blueprint $table): void {
            $table->id();

            $table->uuid('uuid')->unique();
            $table->string('message_id', 255)->nullable();
            $table->string('mailtrap_message_id', 100)->nullable();
            $table->string('from_address', 255);
            $table->string('from_name', 255)->nullable();
            $table->json('to');
            $table->json('cc');
            $table->json('bcc');
            $table->string('recipient_domain', 191)->default('');
            $table->string('subject', 500)->default('');
            $table->longText('html_body')->nullable();
            $table->longText('text_body')->nullable();
            $table->json('headers');
            $table->string('category', 100);
            $table->string('status', 32);
            $table->unsignedBigInteger('target_organization_id')->nullable();
            $table->foreignId('platform_user_id')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->foreignId('mail_thread_id')->nullable()->constrained('mail_threads')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('first_clicked_at')->nullable();
            $table->unsignedInteger('opens_count')->default(0);
            $table->unsignedInteger('clicks_count')->default(0);
            $table->timestamp('last_event_at')->nullable();

            $table->timestamps();

            $table->index('created_at');
            $table->index(['status', 'created_at']);
            $table->index(['category', 'created_at']);
            $table->index(['recipient_domain', 'created_at']);
            $table->index('message_id');
            $table->index('mailtrap_message_id');
            $table->index(['target_organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_mails');
    }
};
