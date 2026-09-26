<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * La clave de idempotencia del apartado (`REQ-LAY-001` RB-12, SP-7, `T-045`).
 *
 * ## Por qué es una columna y no una caché
 *
 * RB-12 pide que un segundo envío con la misma clave **devuelva el apartado ya
 * creado**, y eso tiene dos mitades. La secuencial —el cliente pulsa otra vez
 * cuando la primera ya terminó— la resuelve cualquier registro. La simultánea
 * —dos envíos a la vez— solo la resuelve algo que **la base de datos haga
 * cumplir dentro de la transacción**: una caché se escribe fuera de ella, y
 * entre su lectura y su escritura caben dos apartados. Con
 * `UNIQUE(organization_id, idempotency_key)` el segundo `INSERT` choca, la
 * transacción entera se deshace —ficha, reserva, cupo— y se devuelve el que
 * ganó. Es el mismo mecanismo que la ficha de `T-043` (SP-5).
 *
 * ## Con la organización delante
 *
 * `REQ-ORG-002` RB-3: todo único de negocio lleva la columna del tenant. La
 * clave la genera el navegador al montar el formulario, así que dos negocios
 * pueden recibir la misma sin que eso signifique nada; no es una cuarta
 * excepción de `GlobalUniqueExceptions`, y no tiene que serlo.
 *
 * Nace `NOT NULL`: todo apartado nace de `CreateLayawayAction`, que la exige.
 * Las filas que existieran antes —solo las de desarrollo— reciben una propia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('layaways', function (Blueprint $table): void {
            // Un UUID de 36 caracteres; el ancho deja sitio sin invitar a
            // meter otra cosa.
            $table->string('idempotency_key', 64)->nullable()->after('code');
        });

        DB::table('layaways')->whereNull('idempotency_key')->orderBy('id')->each(function (object $row): void {
            DB::table('layaways')->where('id', $row->id)->update(['idempotency_key' => (string) Str::uuid()]);
        });

        Schema::table('layaways', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable(false)->change();

            $table->unique(['organization_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('layaways', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
