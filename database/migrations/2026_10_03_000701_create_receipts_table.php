<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El comprobante que sube el cliente (`REQ-PAY-001`, `REQ-PAY-002`,
 * `REQ-PAY-003`, `REQ-PAY-004`).
 *
 * Nace en `pending_review` y **no toca el balance** hasta que el negocio lo
 * aprueba: lo que mueve el dinero es el `payment` que crea la aprobación, no
 * esta fila.
 *
 * ## La unicidad es **por organización**
 *
 * `UNIQUE(organization_id, file_hash)` y `UNIQUE(organization_id, bank,
 * reference)` (`REQ-PAY-003` RB-4). Es el cambio del pivote: dos negocios
 * pueden recibir legítimamente la misma referencia bancaria —los bancos
 * venezolanos reciclan y truncan referencias—, y un único global habría
 * impedido cobros reales y, además, le habría dicho a un negocio que otro ya
 * usó esa referencia. Es la base de la última garantía ante dos subidas a la
 * vez (SP-5); la validación (`T-059`) es la primera.
 *
 * ## `bank` y `reference` van juntos, o no van
 *
 * El único de la pareja **no ve** una fila con uno de los dos a `NULL`: en
 * MySQL dos `NULL` nunca chocan. Para el efectivo es justo lo que se quiere
 * (RB-3: sin referencia no hay criterio (b)). Pero un comprobante con
 * referencia y sin banco pasaría el único tantas veces como se subiera, y el
 * duplicado llegaría a la bandeja sin que nada lo dijera. `REQ-PAY-001` RB-2
 * exige los dos salvo en efectivo, así que la base lo exige también: un
 * `CHECK` que rechaza uno sin el otro. Es la regla de «un índice único cuenta
 * filas, no filas visibles» de `T-027`, en su versión de `NULL`.
 *
 * ## Las cuentas de cobro con comprobantes no se borran
 *
 * `payment_account_id` es `restrict` (`REQ-ORG-007` RB-6): la cuenta se
 * desactiva, no se borra, porque el comprobante tiene que seguir diciendo a
 * dónde dijo el cliente que pagó (`REQ-PAY-001` SP-9). `RecordedReceipts`
 * lo comprueba antes, para responder `422` en lugar de dejar que el `DELETE`
 * reviente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // **`restrict`** los tres: un comprobante es la constancia de un
            // pago, y no desaparece porque se borre lo que lo rodea.
            $table->foreignId('layaway_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_account_id')->nullable()->constrained()->restrictOnDelete();

            // `PaymentMethod` (`T-015`). Varchar y no `ENUM` de MySQL ([02 §5]).
            $table->string('payment_method', 20);

            // Ver «`bank` y `reference` van juntos» arriba.
            $table->string('bank', 100)->nullable();
            $table->string('reference', 100)->nullable();

            // El día que el cliente dice haber pagado: un día de **su**
            // calendario, no un instante (`REQ-PAY-001` RB-4).
            $table->date('paid_at');

            // **Sin convertir todavía** (RB-5): lo que el cliente declaró, en
            // la moneda que declaró. La conversión a USD es de la aprobación,
            // con la tasa de ese día, y se congela en `payments`
            // (`REQ-FX-006`). Decimal, no centavos: puede estar en bolívares,
            // y `*_cents` es siempre USD (`REQ-FX-001` RB-9).
            $table->decimal('declared_amount', 16, 2);
            $table->string('declared_currency', 3);

            $table->string('note', 500)->nullable();

            // El archivo, en el disco privado y bajo la ruta de su organización
            // (`REQ-PAY-002`). El hash es el `sha256` del archivo **tal cual
            // se subió**, antes de recomprimir (`REQ-PAY-003` RB-7).
            $table->string('file_path');
            $table->char('file_hash', 64);
            $table->string('file_mime', 100);
            $table->unsignedInteger('file_size');

            // `ReceiptStatus`. Todo comprobante nace en revisión (RB-6).
            $table->string('status', 20)->default('pending_review');

            // Quién lo revisó y cuándo. `nullOnDelete`: los usuarios del
            // negocio se desactivan y no se borran (`REQ-ORG-005`), pero si
            // alguno llegara a borrarse el comprobante no se va con él. Nulo
            // también cuando lo rechaza el sistema al cancelar el apartado
            // (`REQ-LAY-009` SP-3).
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            // `RejectionReasonCode` y el texto que escribió el negocio, que el
            // cliente ve íntegro (`REQ-LAY-022` RB-3).
            $table->string('rejection_reason_code', 30)->nullable();
            $table->string('rejection_reason', 500)->nullable();

            // El reintento de un comprobante rechazado (`REQ-LAY-022` RB-5).
            $table->foreignId('replaces_receipt_id')->nullable()->constrained('receipts')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['organization_id', 'file_hash']);
            $table->unique(['organization_id', 'bank', 'reference']);

            // La bandeja de conciliación: «lo pendiente de este negocio, del
            // más antiguo al más nuevo» (`REQ-PAY-004`).
            $table->index(['organization_id', 'status', 'created_at']);

            // Los comprobantes de un apartado: la expiración, las alertas del
            // portal y el historial (`LayawayReceipts`, `REQ-LAY-022`).
            $table->index(['organization_id', 'layaway_id', 'status']);
        });

        DB::statement('ALTER TABLE receipts ADD CONSTRAINT receipts_bank_with_reference CHECK ((bank IS NULL) = (reference IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
