<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_mail_attachments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('inbound_mail_id')->constrained('inbound_mails')->cascadeOnDelete();
            $table->string('mailtrap_attachment_id', 100);
            $table->string('filename', 255);
            $table->string('content_type', 150)->default('application/octet-stream');
            $table->string('disposition', 20)->default('attachment');
            $table->string('content_id', 255)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('path', 500)->nullable();

            $table->timestamps();

            $table->unique(['inbound_mail_id', 'mailtrap_attachment_id'], 'inbound_mail_attachments_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_mail_attachments');
    }
};
