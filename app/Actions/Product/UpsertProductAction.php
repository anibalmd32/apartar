<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\TaxonomyByName;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\TaxonomySlug;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Alta y edición de un producto con sus condiciones de financiamiento
 * (`REQ-BOF-020`).
 *
 * Las seis reglas de coherencia de RB-1 se comprueban **antes**, en
 * `CoherentFinancingTerms`, porque su sitio es junto al campo que hay que
 * corregir. Lo que queda aquí es lo que no cabe en un `FormRequest`: resolver
 * la taxonomía, proteger la frontera del tenant y dejar el rastro de RB-6.
 *
 * ## La guarda de `product_tag`, que ninguna columna sostiene
 *
 * `product_tag` no lleva `organization_id` —declarado así en `T-024`— y el
 * trait del tenant **no** cubre este caso: lanza cuando alguien asigna a mano
 * un `organization_id` ajeno, no cuando una clave foránea apunta a una fila de
 * otra organización. Una etiqueta del negocio de al lado adjuntada por
 * identificador se guardaría sin que nada fallara, y las dos pantallas
 * seguirían pareciendo correctas porque cada una solo ve su mitad.
 *
 * Así que la guarda va aquí, que es donde `REQ-ORG-002` RB-7 la pone, y es
 * **explícita**: los identificadores se resuelven por consulta del tenant y si
 * alguno no aparece se lanza, en vez de descartarlo en silencio. Descartar
 * daría un producto guardado con menos etiquetas de las que se pidieron y sin
 * nada que lo explicara.
 *
 * ## Se audita el plan de pago, no el producto
 *
 * `ProductPricingChanged` cubre precio, inicial, número de cuotas y frecuencia
 * (RB-6): las condiciones con las que el negocio se compromete a cobrar.
 * Corregir una errata del nombre o ampliar la descripción **no** es un evento
 * de la lista cerrada de `REQ-BOF-005` RB-1, y colarlo aquí convertiría la
 * auditoría en un historial de ediciones que nadie consulta.
 *
 * Y se audita el **cambio**, no el alta: un producto nuevo no cambió sus
 * condiciones, las estrenó, y la entrada diría que pasaron de nada a esto.
 *
 * ## Lo que no está y tiene dueño
 *
 * **El límite del plan de RB-8 no se comprueba aquí**, y es deliberado:
 * `LimitEnforcer` existe con su firma definitiva desde `T-011`, pero quien
 * inserta las doce llamadas es `T-067` y quien enseña a `measure()` a contar
 * `products` es `T-066`. Hoy esa métrica responde «sin límite», así que una
 * llamada adelantada haría cumplir una ficción.
 *
 * **La completitud de RB-5 tampoco.** Imagen, categoría, descripción y
 * condiciones válidas se comprueban **enteras** en la transición a
 * `published`, que escribe `T-030`. Media lista aquí sería la que se queda
 * corta y publica de más sin que nada falle.
 */
final class UpsertProductAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly TenantContext $context,
        private readonly TaxonomyByName $taxonomy,
    ) {}

    /**
     * Crea un producto o guarda los cambios de uno existente.
     *
     * @param  Product|null  $product  el que se edita; `null` crea uno nuevo
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     * @throws CrossTenantRelationException si la categoría o alguna etiqueta son de otra organización
     * @throws ValidationException si el SKU ya existe o el enlace ya no se puede cambiar
     */
    public function handle(ProductData $data, User $actor, ?Product $product = null): Product
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        return DB::transaction(function () use ($data, $actor, $product): Product {
            $before = $product instanceof Product ? $this->auditedTermsOf($product) : [];

            $category = $this->resolveCategory($data);
            $tags = $this->resolveTags($data);

            $this->assertSkuIsFree($data->sku, $product);

            $attributes = [
                'category_id' => $category?->getKey(),
                'sku' => $data->sku,
                'name' => $data->name,
                'description' => $data->description,
                'price_cents' => $data->price,
                'compare_at_price_cents' => $data->compareAtPrice,
                'down_payment_cents' => $data->downPayment,
                'installments_count' => $data->installmentsCount,
                'frequency_days' => $data->frequencyDays,
                'stock' => $data->stock,
                'slug' => $this->resolveSlug($data, $product),
            ];

            if ($product instanceof Product) {
                $product->forceFill($attributes)->save();
            } else {
                // Nace en borrador (`REQ-CAT-007` happy path): publicarlo es
                // una transición explícita con su lista de requisitos, y un
                // alta que entregara el producto publicado escondería ese paso.
                $product = Product::query()->create($attributes + ['status' => ProductStatus::Draft]);
            }

            // `sync` y no `attach`: la lista que llega es la lista completa, y
            // guardar un producto quitándole una etiqueta tiene que quitarla.
            $product->tags()->sync($tags);

            $after = $this->auditedTermsOf($product);

            if ($before !== [] && $before !== $after) {
                // RB-6 · el rastro de las condiciones de pago. Los apartados ya
                // abiertos **no se tocan**: operan sobre su propio snapshot
                // (`REQ-LAY-002` RB-4), así que aquí no hay ningún recorrido
                // que los busque ni debe haberlo.
                $this->audit(
                    AuditEvent::ProductPricingChanged,
                    subject: $product,
                    actor: $actor,
                    before: $before,
                    after: $after,
                );
            }

            return $product;
        });
    }

    /**
     * La categoría del producto: la elegida, la escrita al vuelo, o ninguna.
     *
     * El identificador se resuelve por consulta del tenant, así que el de otra
     * organización **no existe** y lanza en vez de guardarse. El nombre pasa
     * por `TaxonomyByName`, que reutiliza la categoría si ya existe en lugar
     * de fallar por duplicado (`REQ-BOF-023` RB-8, SP-6) — la asimetría con el
     * CRUD de `/categorias`, donde el mismo nombre es `422`, está escrita allí.
     *
     * Nula es una respuesta legítima: un borrador puede estar incompleto y la
     * categoría solo se exige para publicar (RB-5).
     *
     * @throws CrossTenantRelationException si el identificador no es de esta organización
     */
    private function resolveCategory(ProductData $data): ?Category
    {
        if ($data->categoryId !== null) {
            $category = Category::query()->find($data->categoryId);

            if (! $category instanceof Category) {
                throw CrossTenantRelationException::between(Product::class, Category::class);
            }

            return $category;
        }

        if ($data->categoryName !== null && trim($data->categoryName) !== '') {
            return $this->taxonomy->category($data->categoryName);
        }

        return null;
    }

    /**
     * Las etiquetas del producto, elegidas y escritas al vuelo.
     *
     * @return list<int>
     *
     * @throws CrossTenantRelationException si algún identificador no es de esta organización
     */
    private function resolveTags(ProductData $data): array
    {
        $ids = [];

        foreach (array_unique($data->tagIds) as $tagId) {
            $tag = Tag::query()->find($tagId);

            if (! $tag instanceof Tag) {
                throw CrossTenantRelationException::between(Product::class, Tag::class);
            }

            $ids[] = (int) $tag->getKey();
        }

        foreach ($data->tagNames as $name) {
            if (trim($name) === '') {
                continue;
            }

            $ids[] = (int) $this->taxonomy->tag($name)->getKey();
        }

        return array_values(array_unique($ids));
    }

    /**
     * El enlace del producto (RB-3, SP-5).
     *
     * Al crear se genera del nombre y se desambigua con sufijo si está tomado.
     * Al editar se conserva, salvo que se pida otro — y entonces se cierra en
     * cuanto el producto está **publicado**: su URL ya circuló y cambiarla
     * rompería los enlaces que alguien compartió.
     *
     * La condición es «publicado» y no «con productos publicados dentro» ni
     * «con catálogos publicados»: cada slug del sistema tiene la suya y
     * parecerse no las hace la misma.
     *
     * @throws ValidationException si se pidió cambiarlo con el producto publicado
     */
    private function resolveSlug(ProductData $data, ?Product $product): string
    {
        if (! $product instanceof Product) {
            return TaxonomySlug::from($data->slug ?? $data->name, $this->slugIsTaken(), 200);
        }

        $requested = $data->slug === null ? null : TaxonomySlug::normalize($data->slug, 200);

        if ($requested === null || $requested === '' || $requested === $product->slug) {
            return $product->slug;
        }

        if ($product->status === ProductStatus::Published) {
            throw ValidationException::withMessages([
                'slug' => 'El enlace de un producto publicado ya no se puede cambiar: su URL ya circuló y '
                    .'cambiarla rompería los enlaces que compartiste. El nombre sí lo puedes cambiar.',
            ]);
        }

        return TaxonomySlug::from($requested, $this->slugIsTaken($product), 200);
    }

    /**
     * SP-4 · el SKU es único **por organización**, no en la plataforma: dos
     * negocios pueden vender el mismo modelo con el mismo código de fábrica, y
     * prohibirlo sería que el primero que llega se quede con un código que no
     * es suyo (RB-3).
     *
     * **Cuenta también lo borrado lógicamente**, y esa es la línea que evita un
     * `500`: el índice único de la base no sabe de `deleted_at`, así que un
     * producto en la papelera sigue ocupando su SKU. Comprobarlo sin
     * `withTrashed()` daría el `422` correcto casi siempre y un error de base
     * de datos justo cuando alguien reutiliza el código de algo que archivó.
     *
     * @throws ValidationException si ya hay un producto con ese SKU
     */
    private function assertSkuIsFree(string $sku, ?Product $product): void
    {
        $query = Product::withTrashed()->where('sku', $sku);

        if ($product instanceof Product) {
            $query->whereKeyNot($product->getKey());
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'sku' => sprintf('Ya tienes un producto con el SKU «%s».', $sku),
            ]);
        }
    }

    /**
     * Si un enlace ya está usado en la organización activa.
     *
     * Va por `Product::withTrashed()` por lo mismo que el SKU: el índice único
     * cuenta filas, y una que esté en la papelera sigue ocupando su enlace.
     *
     * @return Closure(string): bool
     */
    private function slugIsTaken(?Product $except = null): Closure
    {
        $exceptId = $except?->getKey();

        return function (string $slug) use ($exceptId): bool {
            $query = Product::withTrashed()->where('slug', $slug);

            if ($exceptId !== null) {
                $query->whereKeyNot($exceptId);
            }

            return $query->exists();
        };
    }

    /**
     * Las cuatro condiciones de pago de un producto ya guardado (RB-6).
     *
     * @return array<string, mixed>
     */
    private function auditedTermsOf(Product $product): array
    {
        return [
            'price_cents' => $product->price_cents->cents,
            'down_payment_cents' => $product->down_payment_cents->cents,
            'installments_count' => $product->installments_count,
            'frequency_days' => $product->frequency_days,
        ];
    }
}
