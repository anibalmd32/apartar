<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Account\PortalContext;
use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Layaway;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo que la persona del portal tiene con cada negocio (`REQ-LAY-020`).
 *
 * ## Un bloque por negocio, y nunca mezclados
 *
 * RB-2 es la regla que ordena esta clase: cada deuda es con una empresa
 * distinta, y una lista que juntara los apartados de dos sería un error de
 * fondo. Así que no hay **una** consulta de apartados: hay una **por
 * organización** (RB-9), con su tenant fijado, y cada una produce su propio
 * `BusinessBalance`, que además se niega a recibir un apartado ajeno.
 *
 * ## Una consulta por organización
 *
 * Los apartados de las fichas de la sesión en ese negocio, con el número de
 * cuotas en mora como subconsulta. Los totales salen de esas mismas filas
 * —los balances materializados de `REQ-PAY-008`— sin volver a la base (RB-6):
 * cuarenta apartados no son cuarenta consultas.
 *
 * ## El orden
 *
 * Primero el negocio con el vencimiento más próximo (RB-4), que es la
 * información por la que la persona entró; los que no tienen ninguno, al
 * final, por nombre.
 */
final class CustomerBalanceQuery
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return list<BusinessBalance>
     */
    public function forPortalContext(PortalContext $portal): array
    {
        $byOrganization = [];

        foreach ($portal->customers() as $customer) {
            $byOrganization[(int) $customer->organization_id][] = (int) $customer->getKey();
        }

        $blocks = [];

        foreach ($byOrganization as $organizationId => $customerIds) {
            $organization = Organization::query()->findOrFail($organizationId);

            $blocks[] = $this->context->runFor($organization, fn (): BusinessBalance => new BusinessBalance(
                $organization,
                $this->layawaysOf($customerIds),
            ));
        }

        usort($blocks, function (BusinessBalance $a, BusinessBalance $b): int {
            $first = $a->nextDueDate();
            $second = $b->nextDueDate();

            return match (true) {
                $first !== null && $second !== null => $first->getTimestamp() <=> $second->getTimestamp(),
                $first !== null => -1,
                $second !== null => 1,
                default => strcmp($a->organization->name, $b->organization->name),
            };
        });

        return $blocks;
    }

    /**
     * @param  list<int>  $customerIds
     * @return list<Layaway>
     */
    private function layawaysOf(array $customerIds): array
    {
        return Layaway::query()
            ->whereIn('customer_id', $customerIds)
            ->withCount(['installments as overdue_installments_count' => fn (Builder $installments) => $installments->where('status', InstallmentStatus::Overdue)])
            ->orderByDesc('id')
            ->get()
            ->all();
    }
}
