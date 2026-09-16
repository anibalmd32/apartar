<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `activity_log` pasa a ser una tabla del tenant (`REQ-BOF-005`, [05 §4]
 * bloque 40).
 *
 * La tabla del paquete es global por diseño: `spatie/laravel-activitylog` no
 * sabe nada de organizaciones. La nuestra no puede serlo. Una entrada de
 * auditoría dice **quién tocó el dinero de un negocio concreto**, y SP-6 exige
 * que la de otro sea invisible — no filtrada por un `where` que alguien se
 * acuerde de escribir, sino por el global scope, como el resto del tenant.
 *
 * **`organization_id` es nullable, y no es una concesión.** [05 §4] lo declara
 * así por RB-6: una acción de la plataforma sobre un negocio se registra en la
 * auditoría **de ese negocio** —con su `organization_id` puesto— aunque el
 * actor no sea del tenant. La columna admite nulo para las entradas que el
 * propio paquete pudiera escribir fuera de toda organización; las nuestras van
 * siempre con ella, porque las rellena `BelongsToOrganization`.
 *
 * El índice es `(organization_id, created_at)`: la consulta de la vista es
 * «las entradas de mi negocio, de la más reciente a la más antigua», y ese es
 * exactamente su orden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            // Sin `constrained()`: la clave foránea con borrado en cascada se
            // llevaría la auditoría de una organización cerrada, y
            // `REQ-ORG-008` RB-5 conserva sus datos con `SoftDeletes`. El
            // rastro de quién aprobó qué comprobante tiene que sobrevivir
            // precisamente al momento en que el negocio deja de existir, que
            // es cuando alguien va a preguntar por él.
            $table->unsignedBigInteger('organization_id')->nullable()->after('id');

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'created_at']);
            $table->dropColumn('organization_id');
        });
    }
};
