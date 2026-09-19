<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Las transiciones de estado de un producto (`REQ-CAT-007`).
 *
 * ## Nace a medias, y el relevo está declarado
 *
 * Hoy sabe **archivar**, y nada más. `REQ-BOF-020` RB-10 pone
 * `POST /productos/{product}/archivar` dentro del CRUD, así que `T-027`
 * necesita esa transición — y escribirla en `UpsertProductAction` habría
 * abierto un segundo camino que toca `status`, que es exactamente lo que este
 * proyecto lleva seis tareas evitando. Además el enum de auditoría ya declara
 * a esta clase como emisora de `ProductStatusChanged` desde `T-016`.
 *
 * **`T-030` añade el resto sin reescribir lo que hay:** la matriz completa de
 * transiciones (publicar, despublicar, restaurar a `draft`), la completitud de
 * `REQ-BOF-020` RB-5 con su `IncompleteProductException`, y el ajuste del
 * consumo del plan de RB-8 —que hoy no se puede tocar, porque las llamadas a
 * `LimitEnforcer` las inserta `T-067`—. Lo que **no** hará es duplicar el
 * archivado ni moverlo de sitio. Es el mismo relevo que `T-024` dejó con el
 * modelo `Catalog`.
 *
 * ## Archivar no es borrar, y por eso no rompe nada
 *
 * El producto conserva su fila (RB-2) porque los apartados vigentes operan
 * sobre su *snapshot* y el historial tiene que sobrevivir; conserva también su
 * relación con los catálogos (RB-7), que simplemente dejan de renderizarlo, de
 * modo que desarchivarlo lo devuelve sin recomponer nada. Por eso aquí no hay
 * ningún `delete()` ni ningún recorrido que desate pivotes: archivar cambia
 * una columna y no toca nada más.
 */
final class ChangeProductStatusAction
{
    use LogsSensitiveActivity;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Archiva el producto: deja de verse y deja de poder apartarse (RB-10).
     *
     * Es idempotente. Archivar lo ya archivado no es un error —suele ser el
     * segundo clic de alguien que no vio el primero— y tampoco escribe una
     * entrada de auditoría, porque no ha cambiado nada que auditar.
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     */
    public function archive(Product $product, User $actor): Product
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        return $this->transitionTo($product, ProductStatus::Archived, $actor);
    }

    /**
     * Escribe el estado nuevo y deja su rastro.
     *
     * Privado y con el estado como parámetro a propósito: es el punto por el
     * que `T-030` hará pasar las demás transiciones, así que el `audit()` de
     * `ProductStatusChanged` ya está escrito una sola vez para todas. Lo que
     * aquella tarea añadirá delante es la matriz que decide **qué** paso es
     * legítimo, no otra forma de escribir la columna.
     */
    private function transitionTo(Product $product, ProductStatus $status, User $actor): Product
    {
        if ($product->status === $status) {
            return $product;
        }

        return DB::transaction(function () use ($product, $status, $actor): Product {
            $before = ['status' => $product->status->value];

            $product->forceFill(['status' => $status])->save();

            $this->audit(
                AuditEvent::ProductStatusChanged,
                subject: $product,
                actor: $actor,
                before: $before,
                after: ['status' => $status->value],
            );

            return $product;
        });
    }
}
