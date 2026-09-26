<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Product\ChangeProductStatusAction;
use App\Actions\Product\UpsertProductAction;
use App\Domain\Catalog\FinancingPlan;
use App\Domain\Catalog\ImageSet;
use App\Domain\Catalog\IncompleteProductException;
use App\Domain\Catalog\InventorySort;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Money\Money;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Concerns\ResolvesTableState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\PublishProductRequest;
use App\Http\Requests\Backoffice\StoreProductImagesRequest;
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
use App\Support\TableState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
 * ## El listado es una tabla server-driven
 *
 * Desde `T-038` (`REQ-BOF-045`): pagina, ordena y filtra **en el servidor**
 * con `ResolvesTableState`, y ya no se trae el inventario entero en cada
 * visita —un negocio de plan Business puede tener dos mil productos—. Lleva
 * los filtros de RB-9 (estado, categoría, etiqueta, stock bajo y búsqueda),
 * la columna de en cuántos catálogos está cada producto (`REQ-CAT-007` RB-6) y
 * la marca de última modificación con su autor (SP-7).
 *
 * ## Y es donde se pulsan las cuatro transiciones
 *
 * Publicar, despublicar, restaurar y archivar (`REQ-CAT-007`). Las cuatro son
 * una línea a `ChangeProductStatusAction`, que es quien tiene la matriz y la
 * completitud de RB-5; aquí solo se traduce lo que sale mal. Los dos rechazos
 * posibles llegan ya como `422` —uno como `ValidationException` desde la
 * acción, el otro como `IncompleteProductException` que se reparte por
 * campos—, porque en los dos casos la petición está bien formada y quien la
 * hace tiene permiso: lo que falla son los datos.
 *
 * ## Y el `{product}` llega ya filtrado
 *
 * Por el tenant, así que el identificador de otra organización da `404` y no
 * `403` (SP-11, `REQ-ORG-002` RB-6): no se confirma que exista.
 */
final class ProductController extends Controller
{
    use ProjectsMoney, ResolvesTableState;

    /**
     * Existencias por debajo de las cuales el listado avisa (RB-9).
     *
     * Es un umbral de presentación y no una regla de negocio: `REQ-BOF-020` no
     * fija la cifra, y el filtro existe para que el negocio encuentre rápido lo
     * que se le está acabando.
     */
    private const LOW_STOCK = 5;

    /**
     * Caracteres de la búsqueda que se usan; lo que pase se recorta sin error.
     * Un SKU o un nombre no llegan ni a la mitad.
     */
    private const MAX_SEARCH = 100;

    public function __construct(
        private readonly TenantContext $context,
        private readonly Settings $settings,
        private readonly SpecificationGroupsQuery $groups,
    ) {}

    /**
     * El listado con sus filtros (RB-9), como tabla server-driven.
     *
     * `rows` y `meta` son lo único que pide la recarga parcial de la tabla
     * (`REQ-BOF-045` RB-2), así que van cerrados y comparten una sola
     * consulta: las opciones de los filtros no se vuelven a leer al cambiar de
     * página.
     */
    public function index(Request $request): Response
    {
        $this->authorizeInventory($request);

        $page = null;
        $listing = function () use (&$page, $request): array {
            return $page ??= $this->listing($request);
        };

        return Inertia::render('backoffice/products/Index', [
            'rows' => fn (): array => $listing()['rows'],
            'meta' => fn (): array => $listing()['meta'],
            'categories' => fn (): array => $this->categories(),
            'tags' => fn (): array => $this->tags(),
            'statuses' => fn (): array => $this->statuses(),
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
     * Publica el producto, si está completo (`REQ-CAT-007`, RB-5, SP-3).
     *
     * La lista de lo que falta llega dentro de la excepción y se reparte por
     * campos: cada frase aterriza donde se corrige, y el resumen —el que
     * nombra los tres o cuatro pendientes de una vez— va bajo `status`, que es
     * lo que pinta el listado. Repartirla sin resumen obligaría a quien está
     * en la lista a abrir el producto para enterarse de qué le falta.
     *
     * @throws ValidationException si le falta algo de RB-5 o está archivado (SP-4)
     */
    public function publish(
        PublishProductRequest $request,
        Product $product,
        ChangeProductStatusAction $status,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $status->publish($product, $actor, $request->publishedAt());
        } catch (IncompleteProductException $exception) {
            throw ValidationException::withMessages(
                ['status' => $exception->getMessage()] + $exception->missing,
            );
        }

        return to_route('products.index')->with('status', $product->isScheduled()
            // SP-5 · programado. El mensaje dice la fecha porque quien acaba
            // de pulsar «Publicar» y no ve el producto en su catálogo necesita
            // saber que no ha fallado nada.
            ? sprintf('Producto programado: se verá a partir del %s.', $this->scheduledFor($product))
            : 'Producto publicado. Ya se ve en los catálogos publicados donde lo hayas puesto.');
    }

    /**
     * Devuelve el producto a borrador (`REQ-CAT-007` RB-1).
     *
     * Deja de verse en el acto, y su enlace vuelve a poder editarse
     * (`REQ-BOF-020` SP-5).
     */
    public function unpublish(Request $request, Product $product, ChangeProductStatusAction $status): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $status->unpublish($product, $actor);

        return to_route('products.index')
            ->with('status', 'Producto despublicado. Vuelve a estar en borrador y deja de verse.');
    }

    /**
     * Saca el producto del archivo y lo deja en borrador (SP-4).
     *
     * **Nunca publicado**, y el mensaje lo dice con esas palabras: el producto
     * sigue en los catálogos donde estaba (RB-7), así que volver publicado lo
     * devolvería a la venta sin que nadie hubiera revisado nada.
     */
    public function restore(Request $request, Product $product, ChangeProductStatusAction $status): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $status->restore($product, $actor);

        return to_route('products.index')
            ->with('status', 'Producto restaurado como borrador. Revísalo y publícalo cuando esté listo.');
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
        $product->loadReservedUnits();

        return $this->specificationRows($product) + $this->imageRows($product) + [
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
            // «Existencias» es **lo que hay en la tienda** —disponibles más
            // apartadas que de verdad están—, que es lo que el negocio cuenta
            // y lo que vuelve a enviar; `UpsertProductAction` lo reparte. El
            // desglose va aparte y solo se lee (`REQ-LAY-003` RB-6, SP-2).
            'stock' => $product->onHandUnits(),
            'stockBreakdown' => [
                'available' => $product->stock,
                'reserved' => $product->reservedUnits(),
                'shortfall' => $product->shortfallUnits(),
            ],
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
     * Las fotos del producto, en su orden (`REQ-BOF-022` RB-2, RB-3).
     *
     * La primera es la principal, y eso no es una bandera: es su sitio en la
     * lista. Cada una trae sus tres tamaños ya resueltos por `ImageSet`, que
     * **cae al original mientras la conversión no exista** (`REQ-CAT-005`
     * RB-3), y dos banderas que solo el negocio ve: si aún se está procesando
     * y si su conversión quedó pendiente de revisión (SP-6).
     *
     * @return array{images: list<array<string, mixed>>, maxImages: int}
     */
    private function imageRows(Product $product): array
    {
        $images = $product->getMedia(Product::IMAGES)
            ->map(fn (Media $media): array => [
                'id' => (int) $media->getKey(),
                'set' => ImageSet::of($media)->toArray(),
                // RB-3 · «procesando» por imagen hasta que las tres existan.
                // Se mira la más grande: es la última en terminar.
                'isProcessing' => ! $media->hasGeneratedConversion('zoom'),
                'needsReview' => ImageSet::needsReview($media),
            ])
            ->values()
            ->all();

        return [
            'images' => $images,
            // El tope viaja para que la pantalla deshabilite la subida con su
            // motivo al lado en vez de dejar que la intenten y reciban un
            // `422`. Quien manda sigue siendo el `FormRequest`.
            'maxImages' => StoreProductImagesRequest::MAX_IMAGES,
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
     * Un lote del listado, ya filtrado y ordenado (RB-9, `REQ-BOF-045`).
     *
     * La consulta no lleva un solo `where` de organización: el global scope ya
     * filtra (`REQ-ORG-002` RB-1), y es lo que hace que la misma URL enseñe a
     * cada equipo lo suyo (`REQ-BOF-045` SP-8). `withCount('catalogs')` es la
     * columna de «en cuántos catálogos está» (`REQ-CAT-007` RB-6); `editor` es
     * el autor de la última modificación (SP-7), cargado de una vez para la
     * página y no fila a fila. Las unidades reservadas (`REQ-LAY-003` RB-6)
     * van igual que los catálogos: una subconsulta en el mismo `select`, no
     * una consulta por producto.
     *
     * Paginación **completa**: un inventario tiene techo —el del plan— y
     * contarlo es barato; `REQ-BOF-045` RB-6 reserva la simple para las tablas
     * que crecen sin él.
     *
     * @return array{rows: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function listing(Request $request): array
    {
        $state = $this->tableState($request, InventorySort::whitelist(), InventorySort::Created, descendingByDefault: true);

        $query = Product::query()->with(['category', 'editor:id,name'])->withCount('catalogs')->withReservedUnits();

        $this->applyFilters($query, $request, $state);

        /** @var InventorySort $sort */
        $sort = $state->sort;
        $query->orderBy($sort->column(), $state->direction())->orderBy('products.id', $state->direction());

        $page = $this->paginateTable($query, $state);

        /** @var list<Product> $products */
        $products = array_values($page->items());

        return [
            'rows' => array_map(fn (Product $product): array => $this->listRow($product), $products),
            'meta' => $this->tableMeta($page, $state),
        ];
    }

    /**
     * Los cinco filtros de RB-9, cada uno resuelto contra lo que existe.
     *
     * Un valor que no se puede usar —un estado inventado, la categoría o la
     * etiqueta de otra organización, un identificador que ya no existe— **se
     * ignora y se avisa** (`REQ-BOF-045` SP-4). La categoría y la etiqueta se
     * buscan con el global scope puesto, así que la de otro negocio no existe
     * aquí y no se distingue de una borrada: distinguirlas confirmaría que la
     * del vecino existe.
     *
     * @param  Builder<Product>  $query
     * @param  TableState<InventorySort>  $state
     */
    private function applyFilters(Builder $query, Request $request, TableState $state): void
    {
        $status = $state->filter('status', $request->query('status'), fn (mixed $raw): ?ProductStatus => is_string($raw) ? ProductStatus::tryFrom($raw) : null);

        if ($status instanceof ProductStatus) {
            $query->where('products.status', $status);
        }

        $category = $state->filter('category', $request->query('category'), fn (mixed $raw): ?int => $this->existingId(Category::query(), $raw));

        if ($category !== null) {
            $query->where('products.category_id', $category);
        }

        $tag = $state->filter('tag', $request->query('tag'), fn (mixed $raw): ?int => $this->existingId(Tag::query(), $raw));

        if ($tag !== null) {
            $query->whereHas('tags', fn (Builder $tags): Builder => $tags->whereKey($tag));
        }

        $lowStock = $state->filter('low_stock', $request->query('low_stock'), fn (mixed $raw): ?bool => $raw === '1' ? true : null);

        if ($lowStock === true) {
            $query->where('products.stock', '<=', self::LOW_STOCK);
        }

        $search = $state->filter('q', $request->query('q'), fn (mixed $raw): ?string => is_string($raw) && trim($raw) !== ''
            ? mb_substr(trim($raw), 0, self::MAX_SEARCH)
            : null);

        if (is_string($search)) {
            // La búsqueda del backoffice es por nombre y SKU (RB-9), con
            // `LIKE` y no con el `FULLTEXT` de la tabla: aquel está pensado
            // para la búsqueda del catálogo público, que ordena por relevancia
            // sobre textos largos (`REQ-CAT-002`). Aquí lo que se busca es un
            // código que la persona ya conoce. Los comodines de `LIKE` que
            // escriba se buscan como texto.
            $like = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function (Builder $nested) use ($like): void {
                $nested->where('products.name', 'like', $like)->orWhere('products.sku', 'like', $like);
            });
        }
    }

    /**
     * El identificador si es de una fila de la organización activa, o `null`.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function existingId(Builder $query, mixed $raw): ?int
    {
        if (! is_string($raw) || ! ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }

        $id = $query->whereKey((int) $raw)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Un producto tal como lo pinta la tabla.
     *
     * @return array<string, mixed>
     */
    private function listRow(Product $product): array
    {
        return [
            'id' => (int) $product->getKey(),
            'name' => $product->name,
            'sku' => $product->sku,
            'status' => $product->status->value,
            // `stock` son las **disponibles**: lo que el catálogo deja apartar.
            'stock' => $product->stock,
            'reservedUnits' => $product->reservedUnits(),
            // `REQ-LAY-003` SP-2 · la alerta: apartadas que no están.
            'shortfallUnits' => $product->shortfallUnits(),
            'price' => $this->money($product->price_cents),
            'categoryName' => $product->category?->name,
            'catalogsCount' => (int) $product->getAttribute('catalogs_count'),
            'scheduledFor' => $this->scheduledFor($product),
            // SP-7 · la marca de última modificación con su autor, en la zona
            // del negocio. `updated_by` es nulo en lo que se tocó antes de que
            // existiera la columna: la fecha se sabe y el autor no.
            'updatedAt' => $product->updated_at
                ?->setTimezone($this->context->organization()->timezone)
                ->isoFormat('lll'),
            'updatedBy' => $product->editor?->name,
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
     * Desde cuándo se verá un producto programado, o `null` si no lo está
     * (`REQ-CAT-007` SP-5).
     *
     * Viaja **ya formateada y en la zona horaria del negocio**, igual que el
     * dinero: quien decide si un producto se lista es la consulta contra el
     * reloj del servidor, y dejar la comparación o el formato al cliente daría
     * un sello distinto según la hora del navegador de quien mire.
     */
    private function scheduledFor(Product $product): ?string
    {
        if (! $product->isScheduled()) {
            return null;
        }

        return $product->published_at
            ?->setTimezone($this->context->organization()->timezone)
            ->isoFormat('LLL');
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
