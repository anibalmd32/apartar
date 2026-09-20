<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Product\SyncImageOrderAction;
use App\Domain\Catalog\ImageSet;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\StoreProductImagesRequest;
use App\Http\Requests\Backoffice\SyncImageOrderRequest;
use App\Models\Product;
use App\Models\User;
use App\Support\Media\TenantPathGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Las fotos de un producto (`REQ-BOF-022`).
 *
 * Tres rutas y ninguna más, que son las del contrato: subir, borrar y guardar
 * el orden. Lo que este archivo concentra son los tres límites de la subida
 * —cuántas, cuánto pesan y qué son— y la decisión de SP-4; el orden y la
 * promoción de la principal viven en `SyncImageOrderAction`.
 *
 * ## Cada archivo es su propia unidad de trabajo
 *
 * SP-5: una subida interrumpida no persiste nada **de ese archivo**, y los ya
 * subidos permanecen. Por eso no hay una transacción envolviendo los diez: si
 * el séptimo se corta, los seis primeros son fotos que el negocio ya subió y
 * tirarlas sería perderle el trabajo por un problema de red que no es suyo.
 *
 * ## La cuota de almacenamiento no se comprueba aquí, todavía
 *
 * RB-8 y SP-7 la exigen, y `LimitEnforcer` existe con su firma definitiva
 * desde `T-011` — pero **`T-066`** es quien enseña a `measure()` a contar
 * `storage_mb` y **`T-067`** quien inserta las doce llamadas. Hoy esa métrica
 * responde «sin límite», así que una llamada adelantada haría cumplir una
 * ficción. Está anotado en el plan y no se tapa con un conteo a mano.
 *
 * El `{product}` se resuelve ya filtrado por el tenant, así que el
 * identificador de otra organización da `404` y no `403` (SP-9,
 * `REQ-ORG-002` RB-6): no se confirma que exista.
 */
final class ProductImageController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Sube las fotos y encola sus conversiones (RB-1, RB-3, SP-5).
     */
    public function store(StoreProductImagesRequest $request, Product $product): RedirectResponse
    {
        $this->authorizeInventory($request);

        foreach ($request->images() as $file) {
            $this->attach($product, $file);
        }

        return $this->back($product, 'Imágenes subidas. Las versiones optimizadas se generan en segundo plano.');
    }

    /**
     * Borra la foto y su archivo (RB-4, RB-7, SP-4).
     *
     * **El archivo se borra de verdad**, que es lo contrario de lo que pasa
     * con un comprobante de pago (`REQ-PAY-002` RB-8). La diferencia no es
     * técnica: una foto de catálogo es material del negocio y suya es la
     * decisión de retirarla; un comprobante es la prueba de un pago ajeno y
     * borrarlo destruiría la evidencia de otra persona.
     *
     * @throws ValidationException si es la única foto de un producto publicado
     */
    public function destroy(
        Request $request,
        Product $product,
        int $media,
        SyncImageOrderAction $order,
    ): RedirectResponse {
        $this->authorizeInventory($request);

        /** @var User $actor */
        $actor = $request->user();

        $images = $product->getMedia(Product::IMAGES);

        // `media` está fuera del filtro por organización, así que la única
        // comprobación de pertenencia es esta: la imagen tiene que estar en la
        // colección **de este producto**. Un `404` y no un `403`, porque lo
        // que no es tuyo no existe (`REQ-ORG-002` RB-6).
        $image = $images->first(fn (Media $candidate): bool => (int) $candidate->getKey() === $media);

        if (! $image instanceof Media) {
            abort(404);
        }

        $this->assertNotTheLastImageOfAPublishedProduct($product, $images->count());

        $image->delete();

        // RB-4 · renumerar promueve la siguiente a principal sin escribir una
        // sola línea sobre «cuál es la principal»: la principal es la primera.
        $order->handle($product->fresh() ?? $product, [], $actor);

        return $this->back($product, 'Imagen eliminada.');
    }

    /**
     * Guarda el orden de la galería y los textos alternativos (RB-2, RB-6).
     *
     * @throws ValidationException si alguna imagen no es de este producto
     */
    public function order(
        SyncImageOrderRequest $request,
        Product $product,
        SyncImageOrderAction $order,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $order->handle($product, $request->images(), $actor);
        } catch (CrossTenantRelationException) {
            throw ValidationException::withMessages([
                'images' => 'Alguna de esas imágenes no es de este producto.',
            ]);
        }

        return $this->back($product, 'Galería guardada.');
    }

    /**
     * Añade un archivo a la colección del producto.
     *
     * Dos cosas viajan en `custom_properties` y las dos son declaraciones que
     * solo quien sube puede hacer:
     *
     *  - **de quién es el archivo**, que es lo que `TenantPathGenerator`
     *    necesita para componer la ruta con el `organization_id` dentro (RB-9)
     *    y lo que `TranscodeProductImage` necesita para fijar su tenant. El
     *    generador decide **dónde** va; esto declara **de quién** es;
     *  - **su `alt` por defecto**, «{nombre} — imagen {n}» (`REQ-CAT-005`
     *    RB-4), para que una ficha nunca llegue al cliente con diez imágenes
     *    sin describir.
     */
    private function attach(Product $product, UploadedFile $file): void
    {
        $position = $product->getMedia(Product::IMAGES)->count() + 1;

        $product
            ->addMedia($file)
            ->usingName(ImageSet::defaultAlt($product->name, $position))
            ->withCustomProperties([
                TenantPathGenerator::ORGANIZATION_PROPERTY => $this->context->organizationId(),
            ])
            ->toMediaCollection(Product::IMAGES);
    }

    /**
     * SP-4 · borrar la **única** foto de un producto **publicado** es `422`.
     *
     * No es la comprobación de completitud de `REQ-BOF-020` RB-5, que se hace
     * **al publicar** y mira cuatro cosas a la vez; esa es de `T-030`. Esta
     * mira una sola —que no se quede sin fotos— en el momento contrario: un
     * producto que ya está publicado y visible en un catálogo. En `draft` se
     * permite, porque un borrador puede estar incompleto por definición.
     *
     * @throws ValidationException
     */
    private function assertNotTheLastImageOfAPublishedProduct(Product $product, int $images): void
    {
        if ($images > 1 || $product->status !== ProductStatus::Published) {
            return;
        }

        throw ValidationException::withMessages([
            'images' => 'Es la única imagen de un producto publicado. Sube otra antes de quitarla, o pásalo a '
                .'borrador si quieres dejarlo sin fotos.',
        ]);
    }

    private function back(Product $product, string $status): RedirectResponse
    {
        return to_route('products.edit', $product)->with('status', $status);
    }

    private function authorizeInventory(Request $request): void
    {
        Gate::forUser($request->user())->authorize('manageProducts', $this->context->organization());
    }
}
