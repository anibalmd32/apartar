<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué productos hay en cada catálogo, y en qué orden (`REQ-CAT-023`).
 *
 * ## `created_at` es **funcional, no metadato**
 *
 * Es la columna que ordena el feed y la que decide qué es «novedad»
 * (`REQ-CAT-023` RB-3, `REQ-ACC-003` RB-2 y RB-4): registra cuándo entró el
 * producto **a este catálogo**, y no se toca al reordenar. Reordenar no
 * convierte un producto viejo en novedad.
 *
 * Por eso va explícito y no con `timestamps()`: el `updated_at` que esa
 * llamada añadiría no lo usa nadie, y existiendo invita a que un
 * `SyncCatalogProductsAction` lo actualice al guardar el orden — que es
 * justamente el bug que RB-3 previene, y que se vería como productos de hace
 * meses encabezando el feed de alguien.
 *
 * ## No lleva `organization_id`, y está declarado
 *
 * Es la decisión de esta tarea que más se puede discutir, así que va escrita:
 *
 * A favor de la columna: `catalog_product` **se lee desde la capa de
 * plataforma**. El feed multi-negocio recorre los catálogos que una `Account`
 * sigue y ordena por este `created_at` cruzando organizaciones a propósito, y
 * una tabla que se consulta sin pasar por sus padres no hereda su aislamiento.
 *
 * En contra, y es lo que decidió: esa consulta **cruza organizaciones por
 * diseño**, así que la columna no la filtraría —no habría nada que filtrar— y
 * quedaría como un segundo censo de a quién pertenece la fila, derivable de
 * `catalog_id` y capaz de discrepar de él. El precedente está en la misma
 * situación: `catalog_subscriptions` se lee igual desde la plataforma y está
 * **declarada** en `GlobalUniqueExceptions::tables()`, no dotada de columna.
 *
 * Lo que impide mezclar un producto de un negocio con el catálogo de otro no
 * es esta columna: es la validación de `SyncCatalogProductsAction`
 * (`REQ-CAT-023` RB-1, `REQ-ORG-002` RB-7), que es donde la spec la pone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_product', function (Blueprint $table): void {
            $table->foreignId('catalog_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Contiguo desde 1; la acción recalcula todas en cada guardado
            // (RB-2), así que aquí no hay defecto que valga la pena inventar.
            $table->unsignedInteger('position')->default(0);

            // **Sin `timestamps()`**: ver el bloque de arriba.
            $table->timestamp('created_at')->useCurrent();

            // Un producto aparece una sola vez por catálogo (RB-4).
            $table->primary(['catalog_id', 'product_id']);

            // El orden del feed: lo último que entró a este catálogo.
            $table->index(['catalog_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product');
    }
};
