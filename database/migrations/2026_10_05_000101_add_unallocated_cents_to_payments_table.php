<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El sobrante de cada pago, guardado (`REQ-PAY-008` RB-3, `T-057`).
 *
 * La primera invariante del balance es `paid_cents === sum(payments.amount_cents_usd)
 * − sum(unallocated)`, y [05 §4] no le daba sitio al sobrante. Se podía
 * derivar —lo que el pago trae menos lo que se repartió y menos la inicial que
 * cubrió—, pero entonces la invariante **se cumple sola**: el sobrante sería
 * por definición lo que falta para que cuadre, y una asignación perdida no se
 * vería como un descuadre sino como un sobrante más grande. Guardado en el
 * `INSERT`, es lo que `FifoAllocator` dijo que no cabía (`AllocationPlan::$unallocatedCents`),
 * y la invariante pasa a poder fallar.
 *
 * **Sin valor por defecto**, a propósito: el único que crea pagos es
 * `PaymentApplier` (`T-058`), y un `INSERT` que se olvidara de la columna
 * tiene que fallar en vez de declarar «sin sobrante» por omisión. Es inmutable
 * como el resto del pago (`Payment::IMMUTABLE_COLUMNS`): la reasignación
 * conserva la suma de lo repartido (`REQ-PAY-009` RB-2, SP-7), así que el
 * sobrante no cambia nunca.
 *
 * El `CHECK` lo acota entre cero y el importe: un sobrante negativo no existe
 * (`AllocationPlan` ya lo rechaza) y uno mayor que el pago acreditaría dinero
 * que no entró.
 */
return new class extends Migration
{
    private const CHECK = 'payments_unallocated_within_amount';

    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->bigInteger('unallocated_cents')->after('amount_cents_usd');
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT '.self::CHECK.' CHECK (unallocated_cents >= 0 AND unallocated_cents <= amount_cents_usd)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CHECK '.self::CHECK);

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('unallocated_cents');
        });
    }
};
