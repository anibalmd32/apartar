<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El teléfono del negocio (`REQ-ORG-006`).
 *
 * Cierra el huérfano que dejó `T-010`: `RegisterOrganizationData` declaraba un
 * `phone` que el contrato de `REQ-ORG-001` pide y que **no tenía dónde
 * guardarse**, porque ni `organizations` ni `users` lo declaran en [05 §4].
 *
 * Se resuelve con las dos mitades, y esta es la primera. La columna se añade
 * porque en este mercado el teléfono es el canal real para hablar con un
 * negocio —una suscripción vencida no se cobra por correo— y una columna
 * nullable hoy es mucho más barata que añadirla con quinientas organizaciones
 * dentro. La segunda mitad es que **el registro deja de declararlo**: RB-8
 * quiere un alta sin fricción, y el teléfono pertenece al perfil del negocio,
 * que es esta pantalla.
 *
 * Nullable y sin índice: es un dato de contacto, no una clave. Nadie busca una
 * organización por su teléfono.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('phone', 32)->nullable()->after('document');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('phone');
        });
    }
};
