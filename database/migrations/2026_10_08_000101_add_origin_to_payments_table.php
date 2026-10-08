<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo se pagó cada pago: el método, la cuenta de cobro y el día (`T-062`,
 * `REQ-PAY-007`).
 *
 * `ManualPaymentData` pide al negocio el método, la cuenta a la que le
 * pagaron y la fecha del pago, y [05 §4] no les daba sitio en `payments`:
 * se habrían validado —la fecha no puede ser futura (SP-6)— y después
 * perdido, y un pago sin comprobante no tiene otra evidencia que lo que se
 * escribe al registrarlo (RB-6). Así que el pago **guarda su origen**: el del
 * comprobante cuando se aprueba uno y el del formulario cuando se registra a
 * mano. Los dos caminos quedan iguales, que es lo que pide el test de
 * equivalencia de RB-2, y el historial puede decir «efectivo» en vez de
 * «registrado por el negocio».
 *
 * **Nulas**, porque los pagos de antes no las tenían; los que vienen de un
 * comprobante se rellenan aquí con los datos de su comprobante. Son inmutables
 * como el resto del pago (`Payment::IMMUTABLE_COLUMNS`).
 *
 * La cuenta va con `restrictOnDelete`: una cuenta con pagos no se borra, se
 * desactiva (`REQ-ORG-007` RB-6), y `RecordedReceipts` lo pregunta también a
 * los pagos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('payment_method', 20)->nullable()->after('receipt_id');
            $table->foreignId('payment_account_id')->nullable()->after('payment_method')->constrained()->restrictOnDelete();
            $table->date('paid_on')->nullable()->after('payment_account_id');
        });

        DB::statement(
            'UPDATE payments p JOIN receipts r ON r.id = p.receipt_id '
            .'SET p.payment_method = r.payment_method, p.payment_account_id = r.payment_account_id, p.paid_on = r.paid_at'
        );
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_account_id');
            $table->dropColumn(['payment_method', 'paid_on']);
        });
    }
};
