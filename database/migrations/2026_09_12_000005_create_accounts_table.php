<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas de consumidor (`REQ-ACC-001`).
 *
 * Identidad **global** de navegación: sirve para seguir catálogos y ver el
 * feed, y por sí sola no da acceso a ningún apartado, saldo ni comprobante.
 *
 * **No lleva documento ni teléfono, y es deliberado** (RB-2). El documento
 * identifica al cliente *dentro de un negocio* y vive en `customers`; ponerlo
 * aquí crearía un registro global de cédulas, que es justo lo que el diseño de
 * dos identidades evita. El puente entre ambas exige documento **y** código de
 * apartado (`REQ-ACC-004`).
 *
 * El `email` se indexa pero **no es único**: la clave de la cuenta es
 * `UNIQUE(provider, provider_id)` en `account_identities`, porque el correo de
 * Google puede cambiar y no identifica (RB-3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();

            // Perfil refrescado desde Google en cada inicio de sesión; no es
            // editable en la plataforma (RB-5).
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
