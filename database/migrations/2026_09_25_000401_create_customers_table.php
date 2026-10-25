<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('document_number', 16);

            $table->string('document_kind', 10);

            $table->string('full_name', 200);

            $table->string('phone', 16);

            $table->string('email')->nullable();

            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_ip_address', 45)->nullable();

            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'document_number']);

            $table->unique(['organization_id', 'account_id']);

            $table->index(['organization_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
