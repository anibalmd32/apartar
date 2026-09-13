<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consumo materializado por organización y métrica (`REQ-SUB-003`,
 * [05 §4] bloque 2).
 *
 * El consumo se guarda en vez de calcularse (RB-1): comprobar un límite antes
 * de crear un producto no puede costar seis consultas agregadas cada vez, y
 * esa comprobación ocurre en el camino caliente de casi toda creación.
 *
 * **`UNIQUE(organization_id, metric)`**: una fila por métrica y organización.
 * Sin ella, dos inicializaciones concurrentes dejarían dos contadores de la
 * misma cosa y el límite se comprobaría contra el que tocara leer primero.
 *
 * Aquí solo se crea la tabla y `StartFreeSubscriptionAction` la inicializa en
 * cero. Quien mueve los contadores y los hace cumplir —`UsageCalculator` y
 * `LimitEnforcer`— llega con H5: una tabla de contadores que nadie lee todavía
 * es inofensiva; un contador a medio implementar que ya bloquea creaciones, no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Valor de `UsageMetric`. Como el resto de enums de dominio, se
            // guarda como texto ([05 §4]): un catálogo de métricas que cambia
            // con el producto no se versiona bien como `ENUM` de MySQL.
            $table->string('metric', 32);

            // Nunca negativo, ni siquiera transitoriamente (`REQ-SUB-003`
            // RB-8): la columna sin signo convierte en error lo que de otro
            // modo sería un cupo regalado.
            $table->unsignedBigInteger('value')->default(0);

            $table->timestamp('computed_at');

            $table->timestamps();

            $table->unique(['organization_id', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
