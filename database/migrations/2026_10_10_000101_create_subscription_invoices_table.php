<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las facturas de la suscripción (`REQ-SUB-006`, [05 §4] bloque 2, `T-069`).
 *
 * Las emite la plataforma y las paga el negocio, pero **llevan
 * `organization_id` y son del tenant** para leerlas: el historial de facturas
 * de un negocio es suyo, y el global scope es lo que impide que vea el de
 * otro. La plataforma las recorre todas desde `app/Queries/Platform` y sus
 * Actions (`T-086`), que es donde `tenancy:audit` admite salir del scope.
 *
 * **Una factura emitida es inmutable** en importe, período y plan (RB-3):
 * corregir es anular (`void`) y emitir otra. Lo impide el modelo
 * (`SubscriptionInvoice::IMMUTABLE_COLUMNS`); aquí solo cabe decir que
 * `paid_cents` **sí** se mueve —es lo cobrado, que crece con cada comprobante
 * aprobado (SP-2)— y que el estado también.
 *
 * Sin único por período: anular y volver a emitir el mismo período es
 * legítimo, y la emisión de la renovación es idempotente porque se decide con
 * la fila de la suscripción bloqueada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table): void {
            $table->id();

            // Una factura es la prueba de un cobro: un plan o una organización
            // con facturas no se borran por accidente.
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();

            // `plan_change` (`REQ-SUB-005` RB-2), `renewal` (RB-8) o `manual`
            // (`REQ-PLT-004` RB-5).
            $table->string('kind', 20);

            $table->timestamp('period_start');
            $table->timestamp('period_end');

            // En dólares, como todo el sistema (RB-4).
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('paid_cents')->default(0);
            $table->char('currency', 3)->default('USD');

            $table->string('status', 20)->default('pending');
            $table->date('due_date');
            $table->timestamp('issued_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'due_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
