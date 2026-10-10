<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los comprobantes con los que un negocio paga su suscripción (`REQ-SUB-006`
 * RB-1, RB-2, `T-069`).
 *
 * **Llevan `organization_id`, y por eso sus únicos lo componen** ([05 §4]: o
 * no lleva la columna y se declara no-tenant, o la lleva y el único la
 * incluye). Se eligió llevarla para leerlos por el global scope, como las
 * facturas. El bloqueo de duplicados usa el mismo colaborador que los de
 * clientes (`DuplicateReceipts`, RB-2: «no se reimplementan») pero **mira toda
 * la plataforma** (`SubscriptionReceiptDuplicates`), porque aquí el que cobra
 * es uno solo; estos únicos son la red de la carrera dentro de un negocio.
 *
 * La revisión es de la plataforma: `reviewed_by` apunta a un operador
 * (`platform_users`), nulo mientras no haya quien revise o si lo hizo el
 * sistema.
 *
 * Lo conciliado y la tasa se congelan al aprobar (RB-4), como en `payments`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();

            $table->string('payment_method', 30);
            $table->string('bank', 100)->nullable();
            $table->string('reference', 100)->nullable();
            $table->date('paid_at');
            $table->decimal('declared_amount', 16, 2);
            $table->char('declared_currency', 3);

            $table->string('file_path');
            $table->char('file_hash', 64);
            $table->string('file_mime', 100);
            $table->unsignedInteger('file_size');

            $table->string('status', 20)->default('pending_review');

            // Lo que la plataforma concilió, en su moneda y en dólares, y la
            // tasa con la que se convirtió (RB-4: la del día de la aprobación).
            $table->decimal('settled_amount', 16, 2)->nullable();
            $table->char('settled_currency', 3)->nullable();
            $table->unsignedInteger('settled_cents_usd')->nullable();
            $table->decimal('rate_applied', 18, 8)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->timestamp('rate_published_at')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();

            $table->timestamps();

            // `REQ-PAY-003` RB-2, por organización como en `receipts`.
            $table->unique(['organization_id', 'file_hash']);
            $table->unique(['organization_id', 'bank', 'reference']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_receipts');
    }
};
