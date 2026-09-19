<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Product\ChangeProductStatusAction;
use App\Actions\Product\UpsertProductAction;
use App\Domain\Catalog\FinancingPlan;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Money\Money;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\StoreProductRequest;
use App\Http\Requests\Backoffice\UpdateProductRequest;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\Tag;
use App\Models\User;
use App\Queries\SpecificationGroupsQuery;
use App\Support\Settings;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Lo que el negocio vende (`REQ-BOF-020`).
 *
 * Fino en las escrituras, como los demás: cada una es una línea a su acción.
 * Lo que este archivo decide es **qué se ve**, y ahí hay tres cosas que no son
 * decoración.
 *
 * ## La previsualización del plan se calcula aquí, con el dominio
 *
 * RB-7 pide la frase «inicial $50 + 5 cuotas de $30 cada 15 días» en vivo,
 * **con el mismo cálculo del dominio que generará el cronograma real**. Así
 * que el reparto lo hace `FinancingPlan` con `Money::allocateEvenly()` y viaja
 * ya formateado en las dos monedas; el formulario solo lo pinta.
 *
 * Viaja como una *prop* más de `crear` y de `editar`, y se refresca con una
 * recarga parcial de Inertia (`only: ['plan']`) sobre **esas mismas rutas**.
 * Es lo que evita inventar un séptimo endpoint para un cálculo que ya tiene
 * dónde vivir, y lo que hace que el cliente no pueda dividir por su cuenta: no
 * recibe las cuotas como número, las recibe como texto ya formateado.
 *
 * ## El listado es una lista, todavía
 *
 * Con sus filtros de RB-9 y su columna de en cuántos catálogos está cada
 * producto (`REQ-CAT-007` RB-6), pero sin la tabla genérica de
 * `REQ-BOF-045`: esa la trae `T-038`.
 *
 * ## Y el `{product}` llega ya filtrado
 *
 * Por el tenant, así que el identificador de otra organización da `404` y no
 * `403` (SP-11, `REQ-ORG-002` RB-6): no se confirma que exista.
 */
final class ProductController extends Controller
{
    use ProjectsMoney;

    /**
     * Existencias por debajo de las cuales el listado avisa (RB-9).
     *
     * Es un umbral de presentación y no una regla de negocio: `REQ-BOF-020` no
     * fija la cifra, y el filtro existe para que el negocio encuentre rápido lo
     * que se le está acabando.
     */
    private const LOW_STOCK = 5;

    public function __construct(
        private readonly TenantContext $context,
        private readonly Settings $settings,
        private readonly SpecificationGroupsQuery $groups,
    ) {}

    /**
     * El listado con sus filtros (RB-9).
     */
    public function index(Request $request): Response
    {
        $this->authorizeInventory($request);

        return Inertia::render('backoffice/products/Index', [
            'products' => $this->listing($request),
            'filters' => $this->filters($request),
            'categories' => $this->categories(),
            'statuses' => $this->statuses(),
        ]);
    }

    /**
     * El formulario de alta, con los defaults de la organización (RB-4).
     */
    public function create(Request $request): Response
    {
        $this->authorizeInventory($request);

        return Inertia::render('backoffice/products/Form', $this->formProps($request, null));
    }

    public function store(StoreProductRequest $request, UpsertProductAction $upsert): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $product = $upsert->handle($request->toData(), $actor);
        } catch (CrossTenantRelationException) {
            throw $this->asFieldError();
        }

        return to_route('products.edit', $product)->with('status', 'Producto creado.');
    }

    /**
     * El formulario de edición.
     */
    public function edit(Request $request, Product $product): Response
    {
        $this->authorizeInventory($request);

        return Inertia::render('backoffice/products/Form', $this->formProps($request, $product));
    }

    public function update(UpdateProductRequest $request, Product $product, UpsertProductAction $upsert): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $upsert->handle($request->toData(), $actor, $product);
        } catch (CrossTenantRelationException) {
            throw $this->asFieldError();
        }

        return to_route('products.edit', $product)->with('status', 'Producto guardado.');
    }

    /**
     * Archiva el producto (RB-10).
     *
     * El borrado es siempre lógico: el producto conserva su fila, su historial
     * y su sitio en los catálogos, que simplemente dejan de renderizarlo
     * (`REQ-CAT-007` RB-7, SP-10).
     */
    public function archive(Request $request, Product $product, ChangeProductStatusAction $status): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $status->archive($product, $actor);

        return to_route('products.index')
            ->with('status', 'Producto archivado. Sigue en tus catálogos, sin mostrarse, por si lo recuperas.');
    }

    /**
     * Las props del formulario, iguales para el alta y la edición.
     *
     * @return array<string, mixed>
     */
    private function formProps(Request $request, ?Product $product): array
    {
        return [
            'product' => $product instanceof Product ? $this->productForm($product) : null,
            'defaults' => $this->defaults(),
            'categories' => $this->categories(),
            'tags' => $this->tags(),
            'plan' => $this->plan($request, $product),
            // RB-6 de `REQ-BOF-021` · las secciones que el negocio ya usa, para
            // autocompletar. La consulta va por el global scope: nunca sugiere
            // las del negocio de al lado (SP-8).
            'specificationGroups' => $this->groups->all(),
        ];
    }

    /**
     * La previsualización del plan (RB-7).
     *
     * Los términos salen de la query string cuando el formulario los manda
     * —que es lo que hace la recarga parcial mientras se escribe— y del
     * producto o de los defaults cuando no. La cuenta la hace `FinancingPlan`;
     * aquí solo se decide **con qué cifras**.
     *
     * Devuelve `null` cuando los términos todavía no permiten repartir nada,
     * que es el estado normal de un formulario a medio escribir: la pantalla
     * pinta entonces el hueco, no un plan inventado.
     *
     * @return array<string, mixed>|null
     */
    private function plan(Request $request, ?Product $product): ?array
    {
        $defaults = $this->defaults();

        $plan = FinancingPlan::tryFrom(
            $this->termAmount($request, 'price', $product?->price_cents),
            $this->termAmount($request, 'down_payment', $product?->down_payment_cents),
            $this->termInteger(
                $request,
                'installments_count',
                $product instanceof Product ? $product->installments_count : $defaults['installmentsCount'],
            ),
            $this->termInteger(
                $request,
                'frequency_days',
                $product instanceof Product ? $product->frequency_days : $defaults['frequencyDays'],
            ),
        );

        if (! $plan instanceof FinancingPlan) {
            return null;
        }

        return [
            'downPayment' => $this->money($plan->downPayment),
            'installmentsCount' => $plan->installmentsCount,
            'frequencyDays' => $plan->frequencyDays,
            'installmentAmount' => $this->money($plan->installmentAmount()),
            'lastInstallmentAmount' => $this->money($plan->lastInstallmentAmount()),
            'hasUnevenLastInstallment' => $plan->hasUnevenLastInstallment(),
            'financedAmount' => $this->money($plan->financedAmount()),
            'total' => $this->money($plan->price),
        ];
    }

    /**
     * Un importe de los términos: el que llega del formulario, o el que ya
     * tenía el producto, o cero.
     *
     * Cero y no `null` porque `FinancingPlan::tryFrom()` ya devuelve `null`
     * cuando los términos no cuadran, y un precio de cero no cuadra: la
     * previsualización queda en blanco, que es lo correcto para un formulario
     * vacío.
     */
    private function termAmount(Request $request, string $key, ?Money $fallback): Money
    {
        $value = $request->query($key);

        if (is_string($value) && trim($value) !== '') {
            try {
                return Money::fromDecimalString(trim($value));
            } catch (InvalidArgumentException) {
                // Mientras se teclea, «19.» y «19.999» pasan por aquí. No son
                // un error que merezca un mensaje: lo único que toca es no
                // pintar un plan, y de decirlo se encarga el `422` al guardar.
                return new Money(0);
            }
        }

        return $fallback ?? new Money(0);
    }

    private function termInteger(Request $request, string $key, int $fallback): int
    {
        $value = $request->query($key);

        return is_numeric($value) ? (int) $value : $fallback;
    }

    /**
     * Los defaults de la organización que el formulario precarga (RB-4).
     *
     * Salen **al abrir el formulario** y no al guardar, y la diferencia
     * importa: un default aplicado en el servidor sobre un campo que la
     * persona dejó vacío a propósito es un valor que nadie eligió.
     *
     * @return array<string, int>
     */
    private function defaults(): array
    {
        return [
            'installmentsCount' => $this->settingInteger(SettingKey::DefaultInstallmentsCount),
            'frequencyDays' => $this->settingInteger(SettingKey::DefaultFrequencyDays),
        ];
    }

    private function settingInteger(SettingKey $key): int
    {
        $value = $this->settings->get($key);

        return is_numeric($value) ? (int) $value : (int) $key->defaultValue();
    }

    /**
     * El producto tal como lo carga su formulario.
     *
     * @return array<string, mixed>
     */
    private function productForm(Product $product): array
    {
        $product->loadMissing('tags');

        return $this->specificationRows($product) + [
            'id' => (int) $product->getKey(),
            'name' => $product->name,
            'sku' => $product->sku,
            'slug' => $product->slug,
            'description' => $product->description,
            // En dólares con dos decimales, que es como se escriben (RB-2).
            // El formulario recibe lo mismo que envía: sin esto, editar un
            // producto obligaría a traducir céntimos en el `.tsx`.
            'price' => $this->decimals($product->price_cents),
            'compareAtPrice' => $product->compare_at_price_cents === null
                ? null
                : $this->decimals($product->compare_at_price_cents),
            'downPayment' => $this->decimals($product->down_payment_cents),
            'installmentsCount' => $product->installments_count,
            'frequencyDays' => $product->frequency_days,
            'stock' => $product->stock,
            'status' => $product->status->value,
            'categoryId' => $product->category_id,
            'tagIds' => $product->tags->map(fn (Tag $tag): int => (int) $tag->getKey())->values()->all(),
            // SP-5 · el formulario deshabilita el campo del enlace en lugar de
            // dejar que lo escriban y reciban un `422`. Quien manda sigue
            // siendo la acción.
            'slugIsLocked' => $product->status === ProductStatus::Published,
            'updatedAt' => $product->updated_at?->toIso8601String(),
        ];
    }

    /**
     * La ficha técnica del producto, en su orden de guardado
     * (`REQ-BOF-021` RB-4).
     *
     * Por `position` y a igualdad por `id`, que es el mismo desempate que usa
     * la agrupación de la ficha pública: sin el segundo criterio, dos filas que
     * compartieran posición saldrían en el orden que quisiera MySQL y el
     * formulario mostraría una lista distinta en cada recarga.
     *
     * **Sin `group` traducido a «General»**: aquí sigue siendo `null`, que es
     * lo que el formulario reenvía. Convertirlo a la cadena haría que guardar
     * sin tocar nada creara una sección llamada así.
     *
     * @return array{specifications: list<array<string, mixed>>}
     */
    private function specificationRows(Product $product): array
    {
        $rows = ProductSpecification::query()
            ->where('product_id', $product->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductSpecification $specification): array => [
                'id' => (int) $specification->getKey(),
                'group' => $specification->group,
                'label' => $specification->label,
                'value' => $specification->value,
            ])
            ->values()
            ->all();

        return ['specifications' => $rows];
    }

    /**
     * El listado, ya filtrado (RB-9).
     *
     * La consulta no lleva un solo `where` de organización: el global scope ya
     * filtra (`REQ-ORG-002` RB-1). `withCount('catalogs')` es la columna de
     * «en cuántos catálogos está», que es lo que permite al backoffice avisar
     * de un producto publicado y en cero catálogos (`REQ-CAT-007` RB-6).
     *
     * @return list<array<string, mixed>>
     */
    private function listing(Request $request): array
    {
        $filters = $this->filters($request);

        $query = Product::query()->with('category')->withCount('catalogs');

        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['category'] !== null) {
            $query->where('category_id', $filters['category']);
        }

        if ($filters['lowStock']) {
            $query->where('stock', '<=', self::LOW_STOCK);
        }

        if ($filters['search'] !== null) {
            // La búsqueda del backoffice es por nombre y SKU (RB-9), con
            // `LIKE` y no con el `FULLTEXT` de la tabla: aquel está pensado
            // para la búsqueda del catálogo público, que ordena por relevancia
            // sobre textos largos (`REQ-CAT-002`). Aquí lo que se busca es un
            // código que la persona ya conoce.
            $search = $filters['search'];

            $query->where(function (Builder $nested) use ($search): void {
                $nested->where('name', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%');
            });
        }

        return $query
            ->orderByDesc('id')
            ->get()
            ->map(fn (Product $product): array => [
                'id' => (int) $product->getKey(),
                'name' => $product->name,
                'sku' => $product->sku,
                'status' => $product->status->value,
                'stock' => $product->stock,
                'price' => $this->money($product->price_cents),
                'categoryName' => $product->category?->name,
                'catalogsCount' => (int) $product->getAttribute('catalogs_count'),
            ])
            ->values()
            ->all();
    }

    /**
     * Los filtros aplicados, tal como viajan en la URL (RB-9).
     *
     * @return array{status: string|null, category: int|null, lowStock: bool, search: string|null}
     */
    private function filters(Request $request): array
    {
        $status = $request->query('status');
        $category = $request->query('category');
        $search = $request->query('q');

        return [
            'status' => is_string($status) && ProductStatus::tryFrom($status) instanceof ProductStatus
                ? $status
                : null,
            'category' => is_numeric($category) ? (int) $category : null,
            'lowStock' => $request->boolean('low_stock'),
            'search' => is_string($search) && trim($search) !== '' ? trim($search) : null,
        ];
    }

    /**
     * Las categorías de la organización, para el desplegable y el filtro.
     *
     * @return list<array<string, mixed>>
     */
    private function categories(): array
    {
        return Category::query()
            ->ordered()
            ->get()
            ->map(fn (Category $category): array => [
                'id' => (int) $category->getKey(),
                'name' => $category->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tags(): array
    {
        return Tag::query()
            ->ordered()
            ->get()
            ->map(fn (Tag $tag): array => [
                'id' => (int) $tag->getKey(),
                'name' => $tag->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Los tres estados con su etiqueta, resueltos desde el enum.
     *
     * Sale del enum y no de una lista en el `.tsx` por lo mismo que los seis
     * métodos de pago: dos censos de los mismos tres casos coinciden hasta el
     * día en que uno cambia.
     *
     * @return list<array<string, string>>
     */
    private function statuses(): array
    {
        return array_map(fn (ProductStatus $status): array => [
            'value' => $status->value,
            'label' => match ($status) {
                ProductStatus::Draft => 'Borrador',
                ProductStatus::Published => 'Publicado',
                ProductStatus::Archived => 'Archivado',
            },
        ], ProductStatus::cases());
    }

    /**
     * El importe en dólares con dos decimales, que es como se escribe (RB-2).
     */
    private function decimals(Money $amount): string
    {
        return number_format($amount->cents / 100, 2, '.', '');
    }

    /**
     * Una relación cruzada convertida en error de campo.
     *
     * El intento de adjuntar una etiqueta o una categoría del negocio de al
     * lado no llega por la pantalla —el desplegable solo ofrece las propias—,
     * así que es manipulación de la petición. Aun así se responde `422` junto
     * al campo y no `500`: la petición está bien formada y lo que no se puede
     * es aceptarla.
     */
    private function asFieldError(): ValidationException
    {
        return ValidationException::withMessages([
            'tag_ids' => 'Esa categoría o esa etiqueta no son de tu negocio.',
        ]);
    }

    private function authorizeInventory(Request $request): void
    {
        Gate::forUser($request->user())->authorize('manageProducts', $this->context->organization());
    }
}
