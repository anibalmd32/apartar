<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Catalog\CatalogData;
use App\Actions\Catalog\PublishCatalogAction;
use App\Actions\Catalog\SyncCatalogProductsAction;
use App\Actions\Catalog\UnpublishCatalogAction;
use App\Actions\Catalog\UpsertCatalogAction;
use App\Domain\Catalog\CatalogNotPublishableException;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\SlugLockedException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\PublishCatalogRequest;
use App\Http\Requests\Backoffice\SyncCatalogProductsRequest;
use App\Http\Requests\Backoffice\UpsertCatalogRequest;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Catalog;
use App\Models\Product;
use App\Models\User;
use App\Support\CatalogShareLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los catálogos del negocio (`REQ-BOF-024`).
 *
 * ## Qué decide este archivo
 *
 * Poco: las cuatro escrituras son una línea a su acción. Lo que sí compone es
 * **el estado derivado** —`Draft`, `Listed`, `Unlisted`, `Expired`— y la URL
 * completa con la que el negocio va a difundir la campaña, que son las dos
 * cosas que RB-5 pide ver de un vistazo. Ninguna de las dos se guarda: la
 * primera sale de `Catalog::visibility()` en cada lectura (`REQ-CAT-022`
 * RB-6) y la segunda del slug.
 *
 * ## Los `422` aterrizan donde se corrigen
 *
 * Tres orígenes y tres destinos distintos:
 *
 *  - los requisitos de publicación llegan repartidos por campo desde
 *    `CatalogNotPublishableException`, y el resumen va a `status` — que es lo
 *    que lee el listado, desde donde también se publica;
 *  - el enlace bloqueado va a `slug`, como en la identidad del negocio;
 *  - la composición va a `products`, como la dejó `T-031`.
 *
 * ## Y el `{catalog}` llega ya filtrado
 *
 * Por el tenant, así que el catálogo de otra organización da `404` y no `403`
 * (SP-10, `REQ-ORG-002` RB-6): no se confirma que exista. Lo que el binding no
 * puede comprobar es el otro extremo —que los **productos** sean de esta
 * organización—, y eso lo hace la acción (`REQ-ORG-002` RB-7).
 */
final class CatalogController extends Controller
{
    use ProjectsMoney;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * El listado con su badge de visibilidad y su enlace (RB-5, RB-6).
     */
    public function index(Request $request): Response
    {
        $this->authorizeInventory($request);

        return Inertia::render('backoffice/catalogs/Index', [
            'catalogs' => $this->listing(),
        ]);
    }

    /**
     * El formulario de alta.
     *
     * Sin el compositor: `catalog_product` necesita un `catalog_id` y todavía
     * no lo hay. Crear redirige a la edición, que es donde está (`T-031`).
     */
    public function create(Request $request): Response
    {
        $this->authorizeInventory($request);

        return Inertia::render('backoffice/catalogs/Form', $this->formProps(null, []));
    }

    /**
     * @throws ValidationException si el enlace ya no se puede cambiar (SP-5)
     */
    public function store(UpsertCatalogRequest $request, UpsertCatalogAction $upsert): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $catalog = $this->save($request->toData(), $actor, $upsert, null);

        return to_route('catalogs.edit', $catalog)
            ->with('status', 'Catálogo creado. Añade productos y publícalo cuando esté listo.');
    }

    /**
     * El formulario de edición, con el compositor de `T-031` montado.
     */
    public function edit(Request $request, Catalog $catalog, PublishCatalogAction $publish): Response
    {
        $this->authorizeInventory($request);

        // RB-4 de `REQ-BOF-024` · lo que falta para publicar se explica
        // **antes** de que se pulse el botón, y sale de la misma función que
        // lo rechazaría después. Ver `PublishCatalogAction::blockersFor()`.
        return Inertia::render('backoffice/catalogs/Form', $this->formProps($catalog, $publish->blockersFor($catalog)));
    }

    /**
     * @throws ValidationException si el enlace ya no se puede cambiar (SP-5)
     */
    public function update(
        UpsertCatalogRequest $request,
        Catalog $catalog,
        UpsertCatalogAction $upsert,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        $this->save($request->toData(), $actor, $upsert, $catalog);

        return to_route('catalogs.edit', $catalog)->with('status', 'Catálogo guardado.');
    }

    /**
     * Guarda la composición y el orden del catálogo (`REQ-CAT-023`).
     *
     * Los tres rechazos posibles son `422` y van al mismo campo, `products`,
     * porque los tres hablan de la misma lista: un identificador que no es de
     * este negocio, un archivado que se intenta añadir, y vaciar un catálogo
     * publicado. Es la lista lo que hay que corregir, y es la lista lo que la
     * pantalla tiene delante.
     *
     * @throws ValidationException
     */
    public function products(
        SyncCatalogProductsRequest $request,
        Catalog $catalog,
        SyncCatalogProductsAction $sync,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $sync->handle($catalog, $request->orderedIds(), $actor);
        } catch (CrossTenantRelationException) {
            // No llega por la pantalla —el buscador solo ofrece los productos
            // propios—, así que es manipulación de la petición. Aun así se
            // responde `422` y no `500`: la petición está bien formada y lo
            // que no se puede es aceptarla. El mensaje **no** distingue «no
            // existe» de «es de otro negocio», porque distinguirlo confirmaría
            // que la fila del vecino existe.
            throw ValidationException::withMessages([
                'products' => 'Alguno de esos productos ya no está en tu inventario. Recarga la página y vuelve a '
                    .'guardar.',
            ]);
        }

        return back()->with('status', 'Catálogo actualizado.');
    }

    /**
     * Publica el catálogo, si reúne los requisitos (`REQ-CAT-022` RB-4).
     *
     * La lista de lo que falta llega dentro de la excepción y se reparte por
     * campos: cada frase aterriza donde se corrige —el compositor, las cuentas
     * de cobro, la fecha— y el resumen va bajo `status`, que es lo que pinta
     * el listado. Repartirla sin resumen obligaría a quien publica desde la
     * lista a abrir el catálogo para enterarse de qué le falta. Es el mismo
     * reparto que hace `ProductController::publish()`.
     *
     * @throws ValidationException si le falta alguno de los requisitos de RB-4
     */
    public function publish(
        PublishCatalogRequest $request,
        Catalog $catalog,
        PublishCatalogAction $publish,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $publish->handle($catalog, $request->toData(), $actor);
        } catch (CatalogNotPublishableException $exception) {
            throw ValidationException::withMessages(
                ['status' => $exception->getMessage()] + $exception->missing,
            );
        }

        return to_route('catalogs.edit', $catalog)->with('status', $this->publishedMessage($catalog));
    }

    /**
     * Devuelve el catálogo a borrador (RB-7).
     *
     * La advertencia de RB-9 —los enlaces difundidos dejarán de funcionar, las
     * suscripciones quedan inactivas pero no se pierden— la da la pantalla
     * **antes** de confirmar. Aquí lo que queda es decir qué pasó.
     */
    public function unpublish(
        Request $request,
        Catalog $catalog,
        UnpublishCatalogAction $unpublish,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        $unpublish->handle($catalog, $actor);

        return to_route('catalogs.index')->with(
            'status',
            'Catálogo despublicado. Su enlace deja de funcionar y vuelve a estar en borrador; los apartados que '
            .'salieron de él siguen igual.',
        );
    }

    /**
     * El alta y la edición, con el enlace bloqueado traducido a error de campo.
     *
     * `SlugLockedException` se convierte en `422` junto a `slug` por lo mismo
     * que en la identidad del negocio: la petición está bien formada y quien
     * la hace tiene permiso de sobra; lo que no se puede es aceptarla, y el
     * sitio donde eso se entiende es el campo del enlace.
     *
     * @throws ValidationException
     */
    private function save(
        CatalogData $data,
        User $actor,
        UpsertCatalogAction $upsert,
        ?Catalog $catalog,
    ): Catalog {
        try {
            return $upsert->handle($data, $actor, $catalog);
        } catch (SlugLockedException $exception) {
            throw ValidationException::withMessages(['slug' => $exception->getMessage()]);
        }
    }

    /**
     * Qué se le dice al negocio según cómo quedó el catálogo.
     *
     * RB-6 pide que la diferencia entre `Listed` y `Unlisted` se explique **con
     * esas palabras**, y este es el momento en que hace falta: quien acaba de
     * publicar fuera de la vitrina tiene que entender que su enlace funciona
     * igual, y quien lo publicó dentro tiene que saber que además aparece.
     */
    private function publishedMessage(Catalog $catalog): string
    {
        return $catalog->visibility() === CatalogVisibility::Listed
            ? 'Catálogo publicado y visible en tu vitrina. Cualquiera con el enlace puede verlo.'
            : 'Catálogo publicado fuera de tu vitrina: cualquiera con el enlace puede verlo, aunque no aparezca en '
                .'tu vitrina.';
    }

    /**
     * Las props del formulario, iguales para el alta y la edición.
     *
     * El inventario entero viaja en `availableProducts`, igual que en el
     * listado de `REQ-BOF-020` RB-9: el buscador del compositor filtra en el
     * cliente (`T-031`), sin endpoint nuevo.
     *
     * @param  array<string, string>  $blockers  qué impide publicar hoy, campo → qué corregir
     * @return array<string, mixed>
     */
    private function formProps(?Catalog $catalog, array $blockers): array
    {
        return [
            'catalog' => $catalog instanceof Catalog ? $this->catalogForm($catalog) : null,
            'products' => $catalog instanceof Catalog ? $this->productsOf($catalog) : [],
            'availableProducts' => $this->inventory(),
            'blockers' => $blockers,
        ];
    }

    /**
     * El catálogo tal como lo carga su formulario.
     *
     * @return array<string, mixed>
     */
    private function catalogForm(Catalog $catalog): array
    {
        // `catalogRow()` publica `productsCount`, que en el listado lo pone el
        // `withCount`. Aquí hay que cargarlo: sin esto la cabecera diría cero
        // productos sobre un catálogo lleno, y el cero se lee como un dato.
        $catalog->loadCount('products');

        return $this->catalogRow($catalog) + [
            'description' => $catalog->description,
            'coverUrl' => $this->coverUrl($catalog),
            'updatedAt' => $catalog->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Los catálogos del negocio, con su estado derivado y su enlace (RB-5).
     *
     * Ordenados por identificador descendente, como el inventario: el último
     * creado es el que se está preparando.
     *
     * @return list<array<string, mixed>>
     */
    private function listing(): array
    {
        return Catalog::query()
            ->withCount('products')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Catalog $catalog): array => $this->catalogRow($catalog))
            ->values()
            ->all();
    }

    /**
     * Lo que comparten la fila del listado y la cabecera del formulario.
     *
     * `visibility` y `shareUrl` se componen **aquí y en cada lectura**: no hay
     * columna `visibility` y no debe haberla (`REQ-CAT-022` RB-6), así que una
     * campaña caducada se lee caducada en el mismo instante en que lo está, sin
     * que haya corrido ningún job.
     *
     * @return array<string, mixed>
     */
    private function catalogRow(Catalog $catalog): array
    {
        $visibility = $catalog->visibility();

        return [
            'id' => (int) $catalog->getKey(),
            'name' => $catalog->name,
            'slug' => $catalog->slug,
            'visibility' => $visibility->value,
            'isListed' => $catalog->is_listed,
            'publishedAt' => $catalog->published_at?->toIso8601String(),
            'expiresAt' => $catalog->expires_at?->toIso8601String(),
            // SP-11 y RB-2 · el enlace que le quedó, entero y desde el primer
            // momento. La desambiguación por sufijo es silenciosa, así que el
            // negocio tiene que **ver** su slug antes de difundirlo, no
            // descubrirlo después de pegarlo en un grupo de WhatsApp.
            'shareUrl' => CatalogShareLink::of($catalog->slug),
            // RB-2 · tras publicar es inmutable, y la pantalla lo explica
            // **antes** (`REQ-BOF-024` RB-4) deshabilitando el campo con su
            // motivo al lado. Quien manda sigue siendo la acción.
            'slugIsLocked' => $visibility !== CatalogVisibility::Draft,
            'productsCount' => (int) $catalog->getAttribute('products_count'),
        ];
    }

    /**
     * Los productos del catálogo, en su orden curado.
     *
     * @return list<array<string, mixed>>
     */
    private function productsOf(Catalog $catalog): array
    {
        return $catalog->products()
            ->with('category')
            ->orderBy('position')
            ->get()
            ->map(fn (Product $product): array => $this->productOption($product))
            ->values()
            ->all();
    }

    /**
     * Todo el inventario del negocio, de donde el buscador elige (`T-031`).
     *
     * **Sin filtrar por estado**: un `draft` puede entrar en el catálogo para
     * preparar la campaña antes de lanzarla (`REQ-CAT-023` RB-5). Lo que no
     * se ofrece es el archivado, que se niega al entrar y se tolera al
     * quedarse (SP-1 de `REQ-CAT-023`).
     *
     * @return list<array<string, mixed>>
     */
    private function inventory(): array
    {
        return Product::query()
            ->with('category')
            ->where('status', '!=', ProductStatus::Archived)
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product): array => $this->productOption($product))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function productOption(Product $product): array
    {
        return [
            'id' => (int) $product->getKey(),
            'name' => $product->name,
            'sku' => $product->sku,
            'status' => $product->status->value,
            'price' => $this->money($product->price_cents),
            'categoryName' => $product->category?->name,
        ];
    }

    /**
     * La portada, o `null` si el catálogo no tiene (SP-9).
     *
     * El respaldo —la imagen del primer producto— lo resuelve la vista
     * pública, que es quien tiene que pintar algo: aquí lo útil es saber si
     * hay portada propia o no.
     */
    private function coverUrl(Catalog $catalog): ?string
    {
        $path = $catalog->cover_path;

        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk(UpsertCatalogAction::COVER_DISK)->url($path);
    }

    private function authorizeInventory(Request $request): void
    {
        Gate::forUser($request->user())->authorize('manageProducts', $this->context->organization());
    }
}
