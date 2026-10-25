<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();

            $table->string('display_name');
            $table->string('email');
            $table->string('avatar_url', 512)->nullable();

            $table->string('locale', 10)->default('es');
            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
