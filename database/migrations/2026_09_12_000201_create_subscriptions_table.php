<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suscripción de una organización a un plan (`REQ-SUB-002`, [05 §4] bloque 2).
 *
 * **`UNIQUE(organization_id)`**: toda organización tiene exactamente una
 * suscripción, desde el instante del registro (RB-1). No es una restricción
 * cómoda, es la que hace imposible el estado del que no se sale: una
 * organización con dos suscripciones no tiene plan, tiene una ambigüedad, y
 * cada consulta de límites elegiría la suya.
 *
 * **`current_period_end` es nulo en Free** (RB-2), y eso no es un hueco de
 * datos: es la ausencia deliberada del acantilado. No hay prueba gratuita de
 * plan superior, así que nadie pierde acceso a su inventario porque se le
 * acabó un trial (RB-3).
 *
 * Del bloque 2 se crean aquí dos tablas; `subscription_invoices`,
 * `subscription_receipts` y `support_access_grants` llegan con H5, que es
 * cuando hay quien las escriba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // `restrictOnDelete` y no cascada: borrar un plan que alguien está
            // usando dejaría organizaciones sin plan, que es el estado que
            // REQ-SUB-002 RB-6 prohíbe. Un plan que ya no se vende se
            // despublica (`is_public`), no se borra.
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();

            $table->string('status', 20)->default('active');

            $table->timestamp('started_at');
            $table->timestamp('current_period_start');

            // Nulo = sin fecha de fin. Es lo normal en Free.
            $table->timestamp('current_period_end')->nullable();

            // Fin de la gracia por impago (`REQ-SUB-007` RB-1) y momento de la
            // baja (`REQ-SUB-005`): ambos vacíos mientras nada de eso ocurre.
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->unique('organization_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
