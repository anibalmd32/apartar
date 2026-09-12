<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas de cobro de una organización (`REQ-ORG-007`, `D-7`).
 *
 * Concepto que trajo el pivote: cada negocio declara **dónde le pagan**, y el
 * catálogo se lo muestra al cliente al apartar. Sin al menos una cuenta activa
 * no se puede publicar un catálogo (`REQ-CAT-022`), porque el cliente no
 * sabría a dónde pagar.
 *
 * Una cuenta con comprobantes asociados **no se borra, se desactiva** (RB-6):
 * los comprobantes son evidencia de a dónde se pagó y esa trazabilidad tiene
 * que sobrevivir a que el negocio cierre la cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // `PaymentMethod`: pago_movil | transferencia | zelle |
            // binance_pay | efectivo_usd | efectivo_ves. El enum con sus tres
            // métodos llega con T-015.
            $table->string('method', 20);

            $table->string('bank', 100)->nullable();

            // Obligatorios: en pago móvil venezolano el titular y su cédula
            // son parte del dato de pago, no metadatos (RB-3).
            $table->string('holder_name');
            $table->string('holder_document', 32);

            // Su contenido depende del método (RB-2): teléfono en pago móvil,
            // número de cuenta en transferencia, correo en Zelle, wallet en
            // Binance Pay, y la dirección de retiro en efectivo.
            $table->string('identifier');

            // Texto libre corto que se muestra al cliente, del estilo "indica
            // tu cédula en la referencia" (RB-7, ≤ 300 caracteres).
            $table->string('instructions', 300)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_accounts');
    }
};
