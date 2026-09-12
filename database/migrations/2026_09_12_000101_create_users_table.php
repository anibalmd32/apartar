<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipo de una organización (`REQ-ORG-003`, `REQ-ORG-004`, `REQ-BOF-001`).
 *
 * Sustituye a la migración de `users` que genera Laravel: la nuestra lleva
 * `organization_id` y `role`, así que no puede crearse antes que
 * `organizations`.
 *
 * **`UNIQUE(email)` es global, no por organización**, y es una de las tres
 * excepciones declaradas en `GlobalUniqueExceptions`. El motivo: el login del
 * backoffice no pide organización, se deriva del usuario, de modo que el
 * correo tiene que resolver a un único usuario en toda la plataforma. La
 * consecuencia aceptada es que una persona no puede tener cuenta de equipo en
 * dos negocios con el mismo correo.
 *
 * Ese mismo correo **sí** puede existir a la vez en `platform_users` y en
 * `accounts`: son dominios de identidad distintos (`D-5`, `D-8`).
 */
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

            // Nullable a propósito: un usuario **invitado** existe sin
            // contraseña hasta que acepta la invitación y la establece
            // (`REQ-ORG-004` RB-3). Con la columna obligatoria, la invitación
            // no se podría implementar sin una migración de alteración.
            $table->string('password')->nullable();

            // `OrganizationRole`: owner | member. El enum con su matriz de
            // capacidades llega con T-009; aquí es la columna y su default.
            $table->string('role', 20)->default('member');

            // Un usuario desactivado pierde la sesión en su siguiente
            // petición; no se borra, para conservar su rastro de auditoría.
            $table->timestamp('disabled_at')->nullable();

            // Default `false`: quien tenga que forzar la rotación lo pone en
            // `true` explícitamente. Un default `true` escondería el requisito
            // detrás del valor por defecto.
            $table->boolean('must_change_password')->default(false);

            // Marca de invitación pendiente (`REQ-ORG-004` RB-3).
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
