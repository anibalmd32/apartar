<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Tenancy\TenantContext;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Mueve de golpe todos los productos de una categoría a otra
 * (`REQ-BOF-023` RB-4).
 *
 * Es la salida que hace aceptable el `422` de SP-1. Una categoría con
 * productos no se borra —la FK es `restrict` y un producto sin categoría queda
 * sin sitio—, así que sin esta acción el negocio se quedaría con una categoría
 * que no quiere y sin forma de retirarla salvo editando sus productos uno a
 * uno.
 *
 * ## La pertenencia se comprueba resolviendo, no comprobando
 *
 * El destino llega como identificador y se resuelve con `Category::query()`,
 * que lleva el *global scope* puesto: la categoría del negocio de al lado
 * **no existe** en esa consulta y la respuesta es `404`, no `403` (SP-7,
 * `REQ-ORG-002` RB-6). Un `find()` sin filtrar seguido de un `if` daría el
 * mismo resultado el día que alguien escribiera bien el `if`, y confirmaría
 * que el recurso ajeno existe el día que no.
 *
 * ## Mueve también lo que solo ve la base de datos
 *
 * `withTrashed()`, y esta es la línea que decide si la operación sirve para
 * algo: lo que impide borrar la categoría es la FK, y la FK cuenta **filas**.
 * Reasignar solo los productos visibles dejaría los borrados lógicamente
 * apuntando aquí, y el borrado siguiente fallaría con un error de base de
 * datos en lugar de completarse — después de que la persona hiciera
 * exactamente lo que se le pidió.
 *
 * ## Y no audita
 *
 * El anti-`SHALLOW` de `REQ-BOF-023` menciona auditoría, y la nota del gate lo
 * resuelve: la lista de `REQ-BOF-005` RB-1 es **cerrada** y no tiene ningún
 * evento de taxonomía. Mover productos de una categoría a otra no cambia lo
 * que se vende, ni a qué precio, ni quién puede verlo: cambia cómo se filtra.
 * Lo que sostiene el anti-`SHALLOW` es el lote, la validación de pertenencia y
 * la transacción.
 */
final class ReassignCategoryProductsAction
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Mueve los productos de `$from` a la categoría `$toCategoryId`.
     *
     * @param  int  $toCategoryId  identificador del destino, tal como llegó del formulario
     * @return int cuántos productos se movieron
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     * @throws ModelNotFoundException si el destino no es de esta organización (SP-7)
     */
    public function handle(Category $from, int $toCategoryId, User $actor): int
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        /** @var Category $to */
        $to = Category::query()->findOrFail($toCategoryId);

        if ($to->is($from)) {
            // Mover una categoría a sí misma no es un error que merezca un
            // `422`: es una petición sin efecto. El `FormRequest` ya la
            // rechaza con su mensaje junto al campo; esta guarda existe para
            // el llamante que no pase por él —un comando, un seeder— y para
            // que la cifra devuelta no mienta.
            return 0;
        }

        return DB::transaction(function () use ($from, $to, $actor): int {
            // **Un solo `UPDATE`** y no un recorrido con un `save()` por
            // producto: una categoría con cuatrocientos productos son
            // cuatrocientas consultas y una transacción abierta mientras
            // tanto. Lo que la regla pide es que cambie la columna, no que
            // cada producto se entere.
            //
            // El `UPDATE` masivo de Eloquent mueve `updated_at` de todas las
            // filas, así que mueve también su autor: sin esto, la marca de
            // última modificación (`REQ-BOF-020` SP-7) enseñaría la fecha de
            // la reasignación con el nombre de quien editó antes. Aquí no hay
            // eventos de modelo que lo hagan solos.
            return Product::withTrashed()
                ->where('category_id', $from->getKey())
                ->update(['category_id' => $to->getKey(), 'updated_by' => $actor->getKey()]);
        });
    }
}
