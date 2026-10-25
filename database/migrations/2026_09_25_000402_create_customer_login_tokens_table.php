<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_login_tokens', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('token_hash', 64);

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->string('requested_ip', 45);

            $table->timestamps();

            $table->unique(['organization_id', 'token_hash']);

            $table->index(['customer_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_login_tokens');
    }
};
