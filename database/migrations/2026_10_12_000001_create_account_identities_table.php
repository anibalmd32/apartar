<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_identities', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 20);
            $table->string('provider_id');
            $table->string('email');

            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_identities');
    }
};
