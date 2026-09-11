<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operadores de la plataforma (`REQ-PLT-001`).
 *
 * Tabla y guard completamente separados de `users` (`D-5`, RB-1): no comparten
 * tabla, así que un usuario de organización no puede escalar a la plataforma
 * ni al revés **por construcción**, no por una comprobación que alguien pueda
 * olvidar. Un mismo correo puede existir en `platform_users`, en `users` y en
 * `accounts` sin relación alguna entre ellos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');

            // Un operador desactivado pierde la sesión en su siguiente
            // petición (RB-7); no se borra, para conservar su rastro de
            // auditoría en `platform_activity_log`.
            $table->timestamp('disabled_at')->nullable();

            // El seeder del primer operador lo crea en `true` (RB-2) para
            // forzar la rotación de la credencial que vino del entorno.
            $table->boolean('must_change_password')->default(false);

            $table->timestamps();

            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_users');
    }
};
