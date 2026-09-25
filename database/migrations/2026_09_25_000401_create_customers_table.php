<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La ficha comercial del cliente final, una por organización (`REQ-IDN-001`).
 *
 * ## No tiene contraseña, y la ausencia es el diseño
 *
 * `REQ-IDN-001` RB-5: un cliente **nunca** tiene contraseña. Se entra con
 * documento + código de apartado (`REQ-IDN-008`), con enlace por correo
 * (`REQ-IDN-003`) o con una cuenta vinculada (`REQ-ACC-004`). Una columna
 * `password` aquí sería la invitación a escribir un login que no debe existir,
 * así que no está, y `CustomerSchemaTest` falla el día que alguien la añada.
 *
 * ## La clave es el documento **dentro** de la organización
 *
 * `UNIQUE(organization_id, document_number)` (RB-1, `Q1`): la misma cédula en
 * dos negocios son dos fichas independientes, con balances, historiales y
 * chats separados. Lo contrario —un registro global de cédulas— es justo lo
 * que el modelo de dos identidades evita, y por eso `accounts` tampoco lleva
 * documento (`REQ-ACC-001` RB-2).
 *
 * ## `UNIQUE(organization_id, account_id)` con `account_id` nullable es correcto
 *
 * Parece un error y no lo es. MySQL admite **varios `NULL`** en un índice
 * único —`NULL` no es igual a `NULL`—, así que un negocio puede tener mil
 * fichas sin cuenta vinculada sin que ninguna colisione, y a la vez una misma
 * `Account` no puede vincularse a dos fichas del mismo negocio
 * (`REQ-ACC-004` RB-3). Es exactamente la regla: «como máximo una», no
 * «exactamente una». No hace falta ni un índice parcial ni una columna
 * generada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Forma canónica de `DocumentNumber` (`REQ-IDN-007` RB-3): sin
            // separadores y en mayúscula, `V12345678` o `J123456789`, con los
            // ceros a la izquierda conservados (RB-7). El más largo es un RIF
            // con dígito verificador —11 caracteres—; la normalización es del
            // VO de `T-040`, no de la columna.
            $table->string('document_number', 16);

            // `DocumentKind`: cedula | rif. Varchar y no `ENUM` de MySQL
            // ([02 §5]); el enum llega con `T-040`. Sin valor por defecto a
            // propósito: confundir una cédula con un RIF cambia la naturaleza
            // del cliente (RB-2), y lo decide el VO, no la base.
            $table->string('document_kind', 10);

            $table->string('full_name', 200);

            // Obligatorio (RB-2): es el canal real entre el negocio y su
            // cliente. E.164, `+584121234567`: 16 caracteres como máximo.
            $table->string('phone', 16);

            // Opcional y **sin unicidad** (RB-3): no identifica a nadie, y dos
            // fichas de la misma organización pueden compartirlo.
            $table->string('email')->nullable();

            // El puente con la identidad de consumidor (`REQ-IDN-002`).
            //
            // **`nullOnDelete`**: la ficha es del negocio y la cuenta es de la
            // persona. Si la cuenta desaparece, el negocio conserva a su
            // cliente y su deuda, y el cliente sigue entrando con documento +
            // código. Con `cascade`, borrar una cuenta de Google se llevaría
            // la ficha comercial de cada negocio donde apartó.
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();

            // Consentimiento de términos (`REQ-IDN-005`). La IP admite IPv6.
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_ip_address', 45)->nullable();

            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'document_number']);

            // Ver el bloque de arriba: varios `NULL` no colisionan.
            $table->unique(['organization_id', 'account_id']);

            // La búsqueda del backoffice por teléfono (`REQ-BOF-040`), con el
            // tenant delante porque es el filtro que siempre está.
            $table->index(['organization_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
