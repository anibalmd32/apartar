<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de planes (`REQ-SUB-001`).
 *
 * Los planes son **datos, no código** (RB-1): el sistema nunca compara contra
 * `'pro'` para decidir un límite, consulta la fila. De ahí que cada límite sea
 * una columna explícita y no una constante repartida por el código.
 *
 * **`NULL` en una columna `max_*` significa ilimitado** (RB-2), no "sin
 * definir" ni cero. Es la diferencia entre un plan Business sin tope y un plan
 * Business que no deja crear nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();

            // `code` es el identificador estable del plan (`free`, `pro`,
            // `business`) y lo que hace idempotente a `PlanSeeder` (SP-5).
            $table->string('code', 32);
            $table->string('name');

            $table->bigInteger('price_cents')->default(0);
            $table->string('interval', 20)->default('monthly');

            // Un plan no público sigue funcionando para quien ya está dentro;
            // solo desaparece de /precios (RB-6 y SP-3).
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('position')->default(0);

            // Las siete columnas de límite de RB-2. Todas nullable, y el NULL
            // es la señal de "ilimitado".
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_catalogs')->nullable();
            $table->unsignedInteger('max_products')->nullable();
            $table->unsignedInteger('max_products_per_catalog')->nullable();
            $table->unsignedInteger('max_active_layaways')->nullable();
            $table->unsignedInteger('max_storage_mb')->nullable();
            $table->unsignedInteger('max_payment_accounts')->nullable();

            // Funciones booleanas del plan (`PlanFeature`). Ausente equivale a
            // "sin funciones de pago", no a un error (SP-1).
            $table->json('features')->nullable();

            $table->timestamps();

            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
