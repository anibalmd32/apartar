<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una selección de productos con su propia URL pública (`REQ-CAT-021`,
 * `REQ-CAT-022`).
 *
 * ## `slug` es único en **toda la plataforma**, y es una de las tres
 *
 * Es la URL `apartar.store/c/{slug}`, la que alguien pega en una bio de
 * Instagram o manda por WhatsApp (`Q4`). Si dos negocios pudieran reclamar el
 * mismo, el enlace dejaría de identificar a uno — y el daño no lo sufre quien
 * lo eligió, sino quien hace clic. Por eso el índice es `UNIQUE(slug)` a secas
 * y está declarado en `GlobalUniqueExceptions` con su motivo: `tenancy:audit`
 * falla si aparece una cuarta excepción, así que esta tiene que estar
 * **declarada**, no descubierta.
 *
 * Es exactamente lo contrario de `products.slug`, que es único por
 * organización, y por el motivo contrario: aquel no es una URL de nadie.
 *
 * ## Los tres estados salen de dos columnas
 *
 * No hay columna `status`: la visibilidad se **deriva** (`REQ-CAT-022` RB-6).
 * `published_at` nulo es borrador, con fecha es publicado, y `expires_at`
 * pasada es expirado — evaluado en la consulta y sin job, que es lo que hace
 * que un catálogo expire a su hora aunque la cola esté parada.
 *
 * `is_listed` **no controla el acceso** (RB-3): controla si aparece en la
 * vitrina del negocio. Un catálogo no listado sigue siendo accesible por
 * enlace, y la UI lo dice con esas palabras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 160);

            // Único global: ver el bloque de arriba.
            $table->string('slug', 100);

            $table->text('description')->nullable();
            $table->string('cover_path')->nullable();

            // Aparece en la vitrina del negocio (`REQ-ORG-006` RB-6). No es
            // un control de acceso.
            $table->boolean('is_listed')->default(true);

            // Nulo = borrador. Despublicar lo devuelve a nulo y su URL pasa a
            // dar `404` (RB-7).
            $table->timestamp('published_at')->nullable();

            // Opcional; al pasar, la visibilidad se deriva a expirado (RB-6).
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            // La excepción de [05 §4.1]: sin `organization_id` a propósito.
            $table->unique('slug');

            // El backoffice lista los catálogos de un negocio por estado de
            // publicación, y el tenant va delante porque es el filtro que
            // siempre está.
            $table->index(['organization_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogs');
    }
};
