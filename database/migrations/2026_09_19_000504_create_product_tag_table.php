<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué etiquetas lleva cada producto (`REQ-BOF-023`, `REQ-CAT-006`).
 *
 * ## No lleva `organization_id`, y está declarado
 *
 * Es un pivote **puro** entre dos tablas que ya están aisladas, y a sus filas
 * solo se llega desde un producto o desde una etiqueta —los dos con el global
 * scope del tenant puesto—. Una fila solo puede existir si alguien ya tenía
 * acceso a las dos puntas, así que la columna sería un segundo censo de a quién
 * pertenece la fila, derivable de cualquiera de sus dos padres y capaz de
 * discrepar de ellos.
 *
 * Lo que **no** se hace es dejarlo pasar en silencio: está en
 * `GlobalUniqueExceptions::tables()` con este mismo motivo escrito, que es lo
 * que `tenancy:audit` lee. La presunción de `REQ-QAX-008` RB-4.4 es la
 * contraria a la cómoda —toda tabla se presume del tenant— y quedarse fuera
 * exige justificarlo por escrito.
 *
 * Su diferencia con `catalog_product` está escrita allí: ese otro pivote sí se
 * lee desde la capa de plataforma.
 *
 * ## Borrado en cascada por las dos puntas
 *
 * Una etiqueta se puede borrar en cualquier momento (`REQ-BOF-023` RB-5) y
 * simplemente se desasocia; y un producto borrado de verdad —que solo ocurre
 * si nunca tuvo apartados— se lleva sus asociaciones. Ninguna de las dos
 * filas significa nada sin sus dos extremos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_tag', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            // Sin `id` ni `timestamps()`: la fila **es** la pareja, y no hay
            // nada que preguntarle aparte de si existe. `catalog_product` sí
            // lleva `created_at`, y allí está dicho por qué.
            $table->primary(['product_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_tag');
    }
};
