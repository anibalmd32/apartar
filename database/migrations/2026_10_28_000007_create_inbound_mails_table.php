<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_mails', function (Blueprint $table): void {
            $table->id();

            $table->string('mailtrap_message_id', 100)->unique();
            $table->unsignedBigInteger('mailtrap_inbox_id');
            $table->string('mailtrap_thread_id', 100)->nullable();
            $table->foreignId('mail_thread_id')->constrained('mail_threads')->cascadeOnDelete();
            $table->string('from_address', 255);
            $table->string('from_name', 255)->nullable();
            $table->json('to');
            $table->json('cc');
            $table->string('reply_to', 255)->nullable();
            $table->string('subject', 500)->default('');
            $table->string('message_id', 255)->nullable();
            $table->string('in_reply_to', 255)->nullable();
            $table->json('references');
            $table->json('headers');
            $table->longText('html_body')->nullable();
            $table->longText('text_body')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->json('payload');
            $table->timestamp('received_at');
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['mail_thread_id', 'received_at']);
            $table->index('received_at');
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_mails');
    }
};
