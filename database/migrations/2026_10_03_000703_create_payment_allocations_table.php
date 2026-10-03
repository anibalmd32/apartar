<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué parte de cada pago cubrió qué cuota (`REQ-PAY-005`, `REQ-PAY-009`).
 *
 * Es el reparto FIFO de `FifoAllocator` (`T-056`), escrito: la suma de las
 * filas de un pago es su importe menos lo que no se pudo asignar, y la suma
 * de las de una cuota es su `paid_cents` (`REQ-PAY-008`, las invariantes que
 * comprueba `layaways:reconcile`).
 *
 * ## Lleva `organization_id`, aunque [05 §4] no la listaba
 *
 * La tabla del diseño era `payment_id`, `installment_id`, `amount_cents`, como
 * un pivote. No lo es: tendrá su modelo (`PaymentAllocation`, `T-055`), la
 * reconciliación la sumará por cuota y la reasignación (`REQ-PAY-009`) la
 * reescribe. Un modelo sin la columna del tenant no puede llevar
 * `BelongsToOrganization`, y entonces **toda** consulta sobre él saldría sin
 * filtro: justo lo que `REQ-ORG-002` RB-1 prohíbe. La alternativa era
 * declararla fuera del tenant en `GlobalUniqueExceptions`, como `product_tag`,
 * que es un pivote sin modelo; aquí habría sido ampliar la lista blanca para
 * que el gate callara, que es la señal de que la spec se equivocó. La
 * corrección está escrita en [05 §4] bloque 7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // `cascade` desde el pago: las asignaciones no existen sin él, y la
            // reasignación las reescribe enteras (`REQ-PAY-009`).
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            // `restrict` hacia la cuota: una cuota con dinero asignado no se
            // borra.
            $table->foreignId('installment_id')->constrained()->restrictOnDelete();

            // Centavos de USD, siempre positivos: FIFO nunca asigna en
            // negativo ni sobrepaga una cuota (`REQ-PAY-005`).
            $table->bigInteger('amount_cents');

            $table->timestamps();

            $table->index(['organization_id', 'payment_id']);
            $table->index(['organization_id', 'installment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
