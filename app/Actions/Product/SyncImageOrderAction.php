<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Domain\Catalog\ImageSet;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Fija el orden de las imágenes de un producto y, con él, cuál es la principal
 * (`REQ-BOF-022` RB-2, RB-4, RB-6, SP-8).
 *
 * ## La principal no se elige: es la primera
 *
 * RB-2. No hay una bandera «es la principal» que pueda discrepar del orden, y
 * eso es deliberado: con dos fuentes —una columna y una posición— llega el día
 * en que el catálogo muestra una foto y el Open Graph otra. La imagen de
 * `position = 1` es la de las cards, la del enlace compartido y la del feed.
 *
 * De ahí sale RB-4 sin escribir nada: **borrar la principal promueve a la
 * siguiente** porque al renumerar 1..n la segunda pasa a ser la primera. Lo
 * único que hay que garantizar es que la renumeración sea contigua, que es lo
 * mismo que hacen `CategoryOrder` desde `T-026` y la ficha técnica desde
 * `T-028`.
 *
 * ## La guarda de pertenencia la pone esta acción
 *
 * `media` está **declarada fuera del filtro por organización** —se ata a su
 * modelo por relación polimórfica ([05 §4])—, así que un identificador ajeno
 * en la lista no lo rechaza ningún *global scope*. Sin esta comprobación, una
 * petición con el `media` del negocio de al lado lo renumeraría dentro de este
 * producto. Es la misma guarda que `T-027` puso para las etiquetas y que
 * `REQ-ORG-002` RB-7 coloca en la `Action`.
 *
 * Se comprueba contra **el producto**, no contra la organización: un `media`
 * de otro producto de la misma organización tampoco pinta nada aquí.
 *
 * ## Y gana el último
 *
 * SP-8. Dos personas reordenando la misma galería no tienen un conflicto que
 * resolver, tienen un orden que acaba siendo el de quien guardó después. Lo
 * que no pasa es que nadie desaparezca: lo que no venga en la lista se coloca
 * al final, conservando su orden relativo.
 */
final class SyncImageOrderAction
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Ordena las imágenes del producto y guarda sus textos alternativos.
     *
     * El `alt` viaja con el orden porque es lo que la galería edita en la
     * misma pantalla y en el mismo guardado (RB-6): partirlo en dos peticiones
     * obligaría a una cuarta ruta que el contrato de `REQ-BOF-022` no tiene, y
     * a que reordenar y renombrar pudieran quedar a medias la una de la otra.
     *
     * @param  list<array{id: int, alt?: string|null}>  $images  las imágenes en el orden pedido
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     * @throws CrossTenantRelationException si alguna imagen no es de este producto
     */
    public function handle(Product $product, array $images, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        // `load()` y no `loadMissing()`: esta acción es la dueña de
        // `order_column`, así que tiene que partir de lo que hay en la base y
        // no de lo que trajera en memoria quien la llamó. Con una colección
        // cacheada, un `save()` sobre un modelo que ya «tiene» la posición
        // correcta no escribe nada y la fila se queda con la vieja.
        $product->load('media');

        /** @var array<int, Media> $own */
        $own = $product->getMedia(Product::IMAGES)->keyBy(fn (Media $media): int => (int) $media->getKey())->all();

        DB::transaction(function () use ($images, $own): void {
            $ordered = [];

            foreach ($images as $image) {
                $media = $own[$image['id']] ?? null;

                if (! $media instanceof Media) {
                    // Ni del producto ni, por tanto, de la organización. Se
                    // lanza en vez de descartarlo en silencio: un descarte
                    // dejaría la galería guardada con menos imágenes de las
                    // que se pidieron y sin nada que lo explicara.
                    throw CrossTenantRelationException::between(Product::class, Media::class);
                }

                if (in_array((int) $media->getKey(), $ordered, true)) {
                    // Un identificador repetido es un arrastre mal
                    // serializado, no una intención: gana su primera
                    // aparición.
                    continue;
                }

                $ordered[] = (int) $media->getKey();

                if (array_key_exists('alt', $image)) {
                    $this->writeAlt($media, $image['alt']);
                }
            }

            $this->renumber($own, $ordered);
        });
    }

    /**
     * Escribe el texto alternativo, o lo borra para que vuelva el defecto.
     *
     * Vaciar el campo **no** guarda una cadena vacía: devuelve la imagen a su
     * `alt` por defecto (RB-4). Un `alt` en blanco es una ficha que no se puede
     * leer con lector de pantalla, y dejar que se guarde sería ofrecer esa
     * opción en un campo de texto.
     */
    private function writeAlt(Media $media, ?string $alt): void
    {
        $alt = trim((string) $alt);

        if ($alt === '') {
            $media->forgetCustomProperty(ImageSet::ALT_PROPERTY);
        } else {
            $media->setCustomProperty(ImageSet::ALT_PROPERTY, $alt);
        }

        $media->save();
    }

    /**
     * Renumera de 1 a n, con lo que no vino en la lista al final.
     *
     * `1` y no `0` porque RB-2 habla de `position = 1`, y porque una lista que
     * empieza en cero obliga a traducir en cada sitio que la lea.
     *
     * @param  array<int, Media>  $own  todas las imágenes del producto, por identificador
     * @param  list<int>  $ordered  las pedidas, ya deduplicadas
     */
    private function renumber(array $own, array $ordered): void
    {
        $rest = array_values(array_diff(array_keys($own), $ordered));

        $position = 1;

        foreach ([...$ordered, ...$rest] as $mediaId) {
            $media = $own[$mediaId];

            $media->order_column = $position;
            $media->save();

            $position++;
        }
    }
}
