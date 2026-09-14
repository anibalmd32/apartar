<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El enlace de invitación del equipo (`REQ-ORG-004` RB-4 y SP-3).
 *
 * [05 §4] bloque 3 declara `users` con `invited_at?` y nada más sobre la
 * invitación, pero RB-4 pide un enlace **de 48 h, de un solo uso y
 * reenviable**, y eso no se sostiene sin guardar en algún sitio qué token vale
 * ahora y hasta cuándo. Estas dos columnas son ese sitio.
 *
 * **Van en `users` y no en una tabla propia** porque la invitación no es una
 * entidad aparte: RB-3 dice que el invitado **se crea** al invitarlo —fila de
 * `users`, sin contraseña utilizable— y RB-4 que solo el último enlace
 * funciona. Una a una con la fila y sin historia que conservar, una tabla
 * `team_invitations` sería una partición vertical de `users` con un `JOIN`
 * obligatorio en cada lectura y ninguna pregunta que respondiera mejor.
 *
 * **El índice es `(organization_id, invitation_token_hash)` y no un `UNIQUE`
 * global**, aunque el token sea una credencial y `layaways.code` —que es la
 * misma forma de cosa— sí lo sea. El motivo es que aquí no hace falta: el
 * token lleva delante el identificador de su organización, así que quien llega
 * por el enlace resuelve el tenant **antes** de consultar y la búsqueda ocurre
 * ya filtrada. Un único global obligaría a una cuarta entrada en
 * `GlobalUniqueExceptions`, que es una lista cerrada por buenos motivos
 * (`REQ-QAX-008` RB-4).
 *
 * El token se guarda **hasheado**, como cualquier credencial: quien lea la
 * tabla no puede entrar con lo que ve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // SHA-256 en hexadecimal: 64 caracteres exactos. Nulo cuando no
            // hay invitación viva, que es el estado normal de casi todas las
            // filas —y el que deja un enlace ya usado o ya caducado (SP-3).
            $table->string('invitation_token_hash', 64)->nullable()->after('invited_at');

            // Cuándo deja de valer. Explícito y no derivado de `invited_at`
            // porque el vencimiento es una propiedad del enlace, no de la
            // invitación: un reenvío emite otro enlace con otro plazo sin que
            // cambie cuándo se invitó a la persona.
            $table->timestamp('invitation_expires_at')->nullable()->after('invitation_token_hash');

            $table->index(['organization_id', 'invitation_token_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'invitation_token_hash']);
            $table->dropColumn(['invitation_token_hash', 'invitation_expires_at']);
        });
    }
};
