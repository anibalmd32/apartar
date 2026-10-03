<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un pago conciliado: lo único que mueve el balance de un apartado
 * (`REQ-PAY-005`, `REQ-PAY-007`, `REQ-FX-006`).
 *
 * Nace de aprobar un comprobante o de registrar un pago manual, y las dos
 * vías pasan por el mismo `PaymentApplier` (`T-058`) para dar resultados
 * idénticos.
 *
 * ## El snapshot de la tasa, congelado (`REQ-FX-006`)
 *
 * `amount_cents_usd`, `declared_currency`, `declared_amount`, `rate_applied`,
 * `rate_source` y `rate_published_at`: con qué tasa se convirtió lo declarado,
 * de qué fuente y **cuándo se publicó** esa tasa —no cuándo la ingerimos: un
 * fin de semana las dos fechas se separan tres días, y la que explica el
 * importe es la de publicación—. Nada los actualiza después (RB-3), y la
 * subida de la tasa no recalcula ningún saldo (RB-7). Un pago en USD guarda
 * `rate_applied = 1` (RB-4).
 *
 * ## Un comprobante, un pago
 *
 * `UNIQUE(organization_id, receipt_id)`, con la columna del tenant delante
 * como todo único de negocio (`REQ-ORG-002` RB-3, corregido en [05 §4] tras
 * `T-039`). Aprobar dos veces el mismo comprobante choca aquí. Los pagos
 * manuales llevan `receipt_id` a `NULL`, y esos sí pueden ser varios: dos
 * `NULL` no chocan, que en este caso es lo que se quiere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('layaway_id')->constrained()->restrictOnDelete();
            $table->foreignId('receipt_id')->nullable()->constrained()->restrictOnDelete();

            // Lo que entra en el balance, siempre en centavos de USD
            // (`REQ-FX-006` RB-1).
            $table->bigInteger('amount_cents_usd');

            // --- Snapshot de tasa (`REQ-FX-006`), inmutable ----------------

            $table->string('declared_currency', 3);
            $table->decimal('declared_amount', 16, 2);

            // Escala 8, la de `RateSnapshot` y `Money::convertTo()`
            // (`REQ-FX-001` RB-5).
            $table->decimal('rate_applied', 18, 8);

            // `RateSource`: `bcv`, `parallel` o `manual` (SP-3).
            $table->string('rate_source', 20);

            // Nula solo con una tasa manual, que no tiene publicación.
            $table->timestamp('rate_published_at')->nullable();

            // Cuándo se concilió: la fecha del historial (`REQ-LAY-022` RB-1).
            $table->timestamp('settled_at');

            // Quién lo registró a mano (`REQ-PAY-007` RB-5). Nulo en los que
            // salen de un comprobante aprobado, que ya dicen quién lo revisó.
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'receipt_id']);

            // El historial y la exportación por fecha de conciliación.
            $table->index(['organization_id', 'settled_at']);

            // Los pagos de un apartado: su historial y la reconciliación.
            $table->index(['organization_id', 'layaway_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
