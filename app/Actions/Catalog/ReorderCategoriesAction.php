<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Catalog\CategoryOrder;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Guarda el orden manual de las categorías después de un arrastre
 * (`REQ-BOF-023` RB-2).
 *
 * Es fina a propósito: autoriza, abre la transacción y delega en
 * `CategoryOrder`, que es el único sitio que escribe `position`. La
 * renumeración vive allí y no aquí porque también la necesitan el alta y la
 * baja —crear y borrar tienen que dejar la lista contigua igual que
 * arrastrar—, y tres copias de la misma aritmética son dos que se quedan
 * viejas.
 *
 * **El orden concurrente lo gana el último** (SP-5). Dos personas arrastrando
 * la misma lista no tienen un conflicto que resolver, tienen un orden que
 * acaba siendo el de quien soltó después; montar bloqueo optimista para esto
 * sería rechazarle el guardado a alguien que no hizo nada malo. Lo que sí se
 * garantiza es que **nadie desaparece**: lo que no venga en la lista se va al
 * final en lugar de quedarse sin posición.
 */
final class ReorderCategoriesAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CategoryOrder $order,
    ) {}

    /**
     * @param  list<int>  $orderedIds  identificadores en el orden pedido
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     */
    public function handle(array $orderedIds, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        DB::transaction(function () use ($orderedIds): void {
            $this->order->apply($orderedIds);
        });
    }
}
