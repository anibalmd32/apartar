<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();

            $table->string('password')->nullable();

            $table->string('role', 20)->default('member');

            $table->timestamp('disabled_at')->nullable();

            $table->boolean('must_change_password')->default(false);

            $table->timestamp('invited_at')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->unique('email');
            $table->index(['organization_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
