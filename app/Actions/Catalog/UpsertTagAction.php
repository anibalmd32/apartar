<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Tenancy\TenantContext;
use App\Models\Tag;
use App\Models\User;
use App\Support\TaxonomySlug;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Alta, edición y baja de las etiquetas de un negocio (`REQ-BOF-023`).
 *
 * ## Se borran siempre, y eso no es una inconsistencia con las categorías
 *
 * RB-4 y RB-5 dicen cosas opuestas a propósito. Un producto **tiene** una
 * categoría y quitársela lo dejaría sin sitio; una etiqueta es una marca
 * suelta que se desasocia sin que el producto pierda nada. Por eso la FK de
 * `products.category_id` es `restrict` y el pivote `product_tag` borra en
 * cascada por las dos puntas.
 *
 * El caso incómodo está resuelto en la spec y no hay que inventarlo: borrar
 * una etiqueta que alguien tiene en un enlace de filtro ya compartido
 * **funciona igual**, y el filtro se ignora al abrirlo (SP-4,
 * `REQ-CAT-006` SP-4).
 *
 * ## Y su enlace no se bloquea nunca
 *
 * `REQ-BOF-023` SP-3 cierra el `slug` de una **categoría** con productos
 * publicados, y no dice nada de las etiquetas — coherente con SP-4, que ya
 * acepta que un filtro de etiqueta compartido deje de funcionar. Así que aquí
 * el slug se edita como el nombre. No es un olvido: es la misma decisión vista
 * dos veces.
 *
 * Nada de esto se audita ni consume métrica de plan, por lo mismo que en
 * `UpsertCategoryAction`.
 */
final class UpsertTagAction
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Crea una etiqueta o guarda los cambios de una existente.
     *
     * @param  string|null  $slug  enlace pedido; `null` conserva el que hay y lo genera al crear
     * @param  Tag|null  $tag  la que se edita; `null` crea una nueva
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     */
    public function handle(string $name, ?string $slug, User $actor, ?Tag $tag = null): Tag
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        return DB::transaction(function () use ($name, $slug, $tag): Tag {
            if (! $tag instanceof Tag) {
                $requested = $slug === null ? $name : TaxonomySlug::normalize($slug);

                return Tag::query()->create([
                    'name' => $name,
                    'slug' => TaxonomySlug::from($requested, $this->slugIsTaken(), 80),
                ]);
            }

            // RB-6 · renombrar no arrastra el slug: son dos campos con dos
            // reglas, y el que viaja en las URL compartidas es el segundo.
            $attributes = ['name' => $name];
            $normalized = $slug === null ? null : TaxonomySlug::normalize($slug);

            if ($normalized !== null && $normalized !== '' && $normalized !== $tag->slug) {
                $attributes['slug'] = TaxonomySlug::from($normalized, $this->slugIsTaken($tag), 80);
            }

            $tag->forceFill($attributes)->save();

            return $tag;
        });
    }

    /**
     * Borra la etiqueta y la desasocia de sus productos (RB-5).
     *
     * La desasociación la hace la propia base: `product_tag` borra en cascada
     * desde las dos puntas, declarado así en su migración. Repetirlo aquí con
     * un `detach()` sería una segunda garantía de lo mismo, capaz de
     * desincronizarse de la primera, y el test que lo comprueba mira el
     * resultado y no el camino.
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     */
    public function delete(Tag $tag, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        $tag->delete();
    }

    /**
     * Si un slug ya está usado en la organización activa.
     *
     * Va por `Tag::query()`, así que el *global scope* filtra: la unicidad es
     * por organización, no global.
     *
     * @return Closure(string): bool
     */
    private function slugIsTaken(?Tag $except = null): Closure
    {
        $exceptId = $except?->getKey();

        return function (string $slug) use ($exceptId): bool {
            $query = Tag::query()->where('slug', $slug);

            if ($exceptId !== null) {
                $query->whereKeyNot($exceptId);
            }

            return $query->exists();
        };
    }
}
