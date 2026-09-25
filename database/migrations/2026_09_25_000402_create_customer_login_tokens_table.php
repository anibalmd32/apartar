<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El enlace de acceso por correo del cliente final (`REQ-IDN-003`).
 *
 * ## El índice es `(organization_id, token_hash)`, no un `UNIQUE` global
 *
 * [05 §4] lo declaraba `UNIQUE(token_hash)` a secas, y se corrigió antes de
 * esta migración: habría sido la **cuarta** entrada de `GlobalUniqueExceptions`,
 * una lista cerrada en tres, y `tenancy:audit` falla con la cuarta
 * (`REQ-QAX-008` RB-4). Las tres que hay comparten una propiedad —son claves
 * que se resuelven **antes** de saber en qué organización estamos— y este
 * token no la tiene: RB-3 emite **un enlace por organización**, así que cada
 * token ya pertenece a una sola.
 *
 * **El token lleva delante el identificador de su organización** (`7.a8f3…`),
 * en claro, y el secreto detrás. Quien llega por `/mi-cuenta/acceso/enlace/{token}`
 * fija el tenant con ese prefijo **antes** de consultar esta tabla, y la
 * búsqueda del hash ocurre ya dentro de la organización. Es el patrón de la
 * invitación de equipo desde `T-013` (`…_add_invitation_token_to_users_table`),
 * y va el identificador y no el `slug` por el mismo motivo: el `slug` es
 * editable (`REQ-ORG-006`) y un renombrado no puede romper los enlaces en
 * vuelo. Un prefijo que no es de ninguna organización da el mismo `404` que un
 * token inexistente o caducado (SP-2).
 *
 * Lo que se guarda es **solo el hash** (RB-4): quien lea la tabla no puede
 * entrar con lo que ve. El prefijo no es secreto: dice lo mismo que la
 * columna `organization_id`, y la credencial es lo que va detrás.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_login_tokens', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // **`cascade`**: un token no es historia, es una credencial de 30
            // minutos (RB-4). Sin su ficha no abre nada. La fusión de fichas
            // los traslada al superviviente (`REQ-IDN-006` RB-2) para no romper
            // un enlace en vuelo; lo que protege el historial de la fusión son
            // las claves `restrict` de `layaways`, no esta.
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // SHA-256 en hexadecimal: 64 caracteres exactos.
            $table->string('token_hash', 64);

            // TTL de 30 minutos y un solo uso (RB-4). Emitir uno nuevo
            // invalida los previos no consumidos del mismo cliente (RB-5).
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            // Admite IPv6. Sostiene el rate limit por IP + documento (RB-6).
            $table->string('requested_ip', 45);

            $table->timestamps();

            // Compuesto, no global: ver el bloque de arriba.
            $table->unique(['organization_id', 'token_hash']);

            // RB-5 invalida los tokens vivos de un cliente al emitir otro: la
            // consulta es «los de esta ficha que aún no han caducado».
            $table->index(['customer_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_login_tokens');
    }
};
