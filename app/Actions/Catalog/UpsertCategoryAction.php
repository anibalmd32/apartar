<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Catalog\CategoryInUseException;
use App\Domain\Catalog\CategoryOrder;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\TaxonomySlug;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Alta, edición y baja de las categorías de un negocio (`REQ-BOF-023`).
 *
 * ## El nombre se edita libre; el enlace no
 *
 * Son dos campos con dos reglas opuestas y esa asimetría **es** el requisito.
 * Renombrar no toca el `slug` (RB-6): corregir una errata en «Electrodomesitos»
 * no puede romper los filtros que el negocio ya repartió por WhatsApp, y si
 * renombrar arrastrara el slug cada corrección los rompería en silencio — el
 * mismo daño que `REQ-ORG-006` SP-5 evita para la vitrina. Cambiar el slug es
 * una acción explícita, y se cierra en cuanto hay productos publicados
 * (RB-3, SP-3).
 *
 * ## Y el mismo nombre dos veces es un error aquí, no allí
 *
 * En esta pantalla el nombre duplicado da `422` (SP-2): la intención es «crea
 * una categoría nueva» y ya existe. Desde el formulario de producto la
 * intención es otra —«pon el producto en esta categoría»— y entonces se
 * reutiliza (RB-8, SP-6). Esa segunda mitad vive en `TaxonomyByName` y la
 * llamará `T-027`; el `422` de aquí lo pone `UniqueTaxonomyName` en el
 * `FormRequest`, que es donde el error llega junto a su campo.
 *
 * ## Nada de esto se audita, y está decidido
 *
 * La lista de `REQ-BOF-005` RB-1 es **cerrada** y su bloque de Catálogo cubre
 * el precio, el financiamiento y el estado de un producto y la publicación de
 * catálogos. Crear una categoría no cambia lo que se vende, ni a qué precio,
 * ni quién puede verlo. Tampoco consume métrica de plan (RB-9): son metadatos,
 * y limitarlos no aportaría nada.
 */
final class UpsertCategoryAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CategoryOrder $order,
    ) {}

    /**
     * Crea una categoría o guarda los cambios de una existente.
     *
     * @param  string  $name  nombre tal como lo escribió el negocio
     * @param  string|null  $slug  enlace pedido; `null` conserva el que hay y lo genera al crear
     * @param  Category|null  $category  la que se edita; `null` crea una nueva
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     * @throws CategoryInUseException si se pidió cambiar el slug con productos publicados (SP-3)
     */
    public function handle(string $name, ?string $slug, User $actor, ?Category $category = null): Category
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        return DB::transaction(function () use ($name, $slug, $category): Category {
            if (! $category instanceof Category) {
                return $this->create($name, $slug);
            }

            // **El enlace se decide antes de escribir nada.** El guardado es
            // todo o nada: un `422` sobre el slug no puede dejar el renombrado
            // aplicado, porque entonces la pantalla diría «no se guardó»
            // enseñando un nombre que sí se guardó. Quien solo quiere
            // renombrar manda `name` y no `slug`, y eso nunca se rechaza
            // (RB-6).
            $attributes = ['name' => $name];
            $newSlug = $slug === null ? null : $this->resolveSlug($category, $slug);

            if ($newSlug !== null) {
                $attributes['slug'] = $newSlug;
            }

            $category->forceFill($attributes)->save();

            return $category;
        });
    }

    /**
     * Borra la categoría, **salvo que tenga productos** (RB-4, SP-1).
     *
     * A diferencia de una cuenta de cobro, que se degrada a desactivada, aquí
     * no hay degradación posible: un producto **tiene** una categoría, y
     * quitársela lo dejaría sin sitio. Por eso la FK es `restrict` desde
     * `T-024` y por eso la salida que ofrece la UI es reasignar primero.
     *
     * **El conteo mira también lo borrado lógicamente**, y no es un detalle:
     * la FK cuenta filas, no productos visibles. Una categoría cuyos únicos
     * productos están en la papelera seguiría sin poder borrarse, y comprobarlo
     * con el conteo de la pantalla daría un error de base de datos en lugar del
     * `422` con su explicación.
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     * @throws CategoryInUseException si la categoría tiene productos dentro
     */
    public function delete(Category $category, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        $products = $this->productsIn($category);

        if ($products > 0) {
            throw CategoryInUseException::cannotBeDeleted($category, $products);
        }

        DB::transaction(function () use ($category): void {
            $category->delete();

            // Sin esto, borrar la tercera de cinco dejaría las posiciones en
            // 1, 2, 4, 5: la lista se pinta igual y el siguiente arrastre
            // calcula sobre una numeración que no coincide con lo que se ve.
            $this->order->apply();
        });
    }

    /**
     * Da de alta la categoría con su enlace y al final de la lista.
     *
     * El slug se genera del nombre cuando no se pide uno (RB-3) y se
     * desambigua con sufijo si está tomado: dos nombres distintos producen el
     * mismo slug con facilidad —«Ropa niño» y «Ropa-niño»— y rechazar el
     * segundo obligaría a renombrar una categoría legítima.
     */
    private function create(string $name, ?string $slug): Category
    {
        $requested = $slug === null ? $name : TaxonomySlug::normalize($slug);

        $category = Category::query()->create([
            'name' => $name,
            'slug' => TaxonomySlug::from($requested, $this->slugIsTaken(), 120),

            // Antes del `INSERT` y no después: una fila con la posición por
            // defecto se colaría delante de todas al renumerar.
            'position' => $this->order->next(),
        ]);

        $this->order->apply();

        return $category->refresh();
    }

    /**
     * El enlace que hay que escribir, o `null` si no cambia (RB-3, SP-3).
     *
     * Devuelve `null` —en vez de lanzar— cuando el slug pedido es el que ya
     * tiene: un formulario que reenvía el campo sin tocarlo no está pidiendo
     * cambiar nada, y rechazarlo haría imposible renombrar una categoría con
     * productos publicados, que es justo lo que RB-6 permite.
     *
     * @throws CategoryInUseException si el cambio es real y tiene productos publicados
     */
    private function resolveSlug(Category $category, string $slug): ?string
    {
        $slug = TaxonomySlug::normalize($slug);

        if ($slug === '' || $slug === $category->slug) {
            return null;
        }

        $published = $this->publishedProductsIn($category);

        if ($published > 0) {
            throw CategoryInUseException::slugIsLocked($category, $published);
        }

        return TaxonomySlug::from($slug, $this->slugIsTaken($category), 120);
    }

    /**
     * Cuántos productos cuelgan de la categoría, incluidos los que solo
     * existen para la base de datos.
     *
     * `withTrashed()` porque la FK `restrict` mira filas: un producto borrado
     * lógicamente sigue apuntando aquí y sigue impidiendo el borrado.
     */
    private function productsIn(Category $category): int
    {
        return Product::withTrashed()->where('category_id', $category->getKey())->count();
    }

    /**
     * Cuántos de sus productos están publicados (SP-3).
     *
     * Es el estado y no la fecha: un producto con `published_at` futuro ya
     * está comprometido a publicarse (`REQ-CAT-007` RB-5), y dejar cambiar el
     * enlace hasta el día antes sería romperlo justo cuando empieza a
     * circular. Los borrados lógicamente no cuentan aquí —no se publican— y
     * por eso este conteo no es el mismo que el del borrado.
     */
    private function publishedProductsIn(Category $category): int
    {
        return Product::query()
            ->where('category_id', $category->getKey())
            ->where('status', ProductStatus::Published)
            ->count();
    }

    /**
     * Si un slug ya está usado en la organización activa.
     *
     * Va por `Category::query()`, así que el *global scope* del tenant filtra
     * y el slug de otro negocio no estorba: la unicidad es por organización
     * ([05 §4]), al revés que la del catálogo.
     *
     * @return Closure(string): bool
     */
    private function slugIsTaken(?Category $except = null): Closure
    {
        $exceptId = $except?->getKey();

        return function (string $slug) use ($exceptId): bool {
            $query = Category::query()->where('slug', $slug);

            if ($exceptId !== null) {
                $query->whereKeyNot($exceptId);
            }

            return $query->exists();
        };
    }
}
