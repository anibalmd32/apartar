<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La clave de idempotencia del pago manual (`REQ-PAY-007` SP-5, `T-055`).
 *
 * `ManualPaymentData` la lleva en el contrato de [22], y [05 §4] no le daba
 * sitio en `payments`. Es el mismo mecanismo que la del apartado (`T-045`):
 * un doble clic sobre «Registrar pago» manda dos peticiones con la misma
 * clave, y solo un único que **la base haga cumplir dentro de la transacción**
 * impide los dos pagos cuando llegan a la vez. Una comprobación en PHP deja
 * caber dos `INSERT` entre la lectura y la escritura, y aquí lo que se
 * duplicaría es dinero acreditado.
 *
 * **Nula en los pagos que salen de un comprobante**, que ya son únicos por
 * `(organization_id, receipt_id)`. Varios `NULL` no chocan, que en este caso
 * es lo que se quiere. Con la organización delante, como todo único de
 * negocio (`REQ-ORG-002` RB-3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->after('receipt_id');

            $table->unique(['organization_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
