<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Catalog\SyncCatalogProductsAction;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\SyncCatalogProductsRequest;
use App\Models\Catalog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Los catálogos del negocio (`REQ-BOF-024`).
 *
 * ## Nace con una sola operación, y el relevo está declarado
 *
 * Hoy sabe **componer**: qué productos entran en un catálogo y en qué orden
 * (`REQ-CAT-023`). El CRUD —nombre, descripción, portada, `is_listed`— y la
 * publicación con sus seis requisitos son de **`T-032`**, junto con el listado
 * con badge de visibilidad y la pantalla que montará `CatalogProductSorter`.
 * `REQ-BOF-024` reparte sus rutas entre las dos tareas y esta escribe la suya
 * en lugar de esperar: la composición tiene su propio requisito cerrado y su
 * propia acción, y dejarla dentro del CRUD habría metido la escritura del
 * pivote en el guardado del formulario, que es un segundo camino hacia
 * `catalog_product`.
 *
 * Por eso el redirect es `back()` y no una ruta con nombre: la pantalla de
 * catálogos todavía no existe, y nombrar una ruta que llega en `T-032` sería
 * una promesa que hoy no se puede resolver.
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
}
