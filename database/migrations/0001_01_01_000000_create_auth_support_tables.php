<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas de apoyo de la autenticación, del bloque 0 de [05 §4].
 *
 * Este archivo lo generó Laravel creando también `users`. **La tabla `users`
 * se sustituye** (`T-008`): la nuestra lleva `organization_id` y `role`, y no
 * puede crearse antes que `organizations`. Vive en su propia migración del
 * bloque 3; aquí quedan las dos que no dependen de nadie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();

            // Sin clave foránea a `users`, y a propósito: la sesión se crea
            // antes de que haya nadie autenticado y sobrevive al cierre de
            // sesión.
            $table->foreignId('user_id')->nullable()->index();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
