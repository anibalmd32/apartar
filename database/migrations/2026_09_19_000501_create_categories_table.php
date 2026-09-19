<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taxonomía del inventario: las categorías de un negocio (`REQ-BOF-023`,
 * `REQ-CAT-006`).
 *
 * **La taxonomía es de cada organización** (RB-1): no hay categorías
 * compartidas ni sugeridas por la plataforma, y por tanto tampoco comparables
 * entre negocios. Una ferretería y una tienda de ropa no tienen por qué
 * coincidir en cómo ordenan lo que venden.
 *
 * **Planas y ordenadas a mano** (RB-2): no hay jerarquía, y `position` guarda
 * el orden que el negocio decide arrastrando.
 *
 * Su relación con `products` es `restrict` y no `cascade` (RB-4), pero eso se
 * declara del lado de `products`: borrar una categoría con productos dentro
 * tiene que fallar, porque la alternativa silenciosa es que desaparezca el
 * producto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);

            // Se autogenera del nombre y solo es editable mientras no haya
            // productos publicados asociados (RB-3, RB-6): los filtros del
            // catálogo viajan por URL y cambiarlo rompe los enlaces que
            // alguien ya compartió.
            $table->string('slug', 120);

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            // RB-3 · **name y slug, los dos únicos por organización.** El slug
            // lo dice [05 §4]; el nombre lo exige el requisito y hace falta en
            // la base porque RB-8 reutiliza la categoría existente cuando se
            // crea una al vuelo desde el formulario de producto: sin el índice,
            // dos peticiones simultáneas con el mismo nombre crean dos
            // categorías y el negocio acaba con la taxonomía duplicada.
            $table->unique(['organization_id', 'slug']);
            $table->unique(['organization_id', 'name']);

            // El orden se lee siempre dentro de una organización.
            $table->index(['organization_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
