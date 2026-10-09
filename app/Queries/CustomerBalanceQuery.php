<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Account\PortalContext;
use App\Domain\Layaway\DueCalendar;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use App\Support\Settings;
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
 * Los apartados de las fichas de la sesión en ese negocio, con lo que sus
 * cuotas dicen de los vencimientos como subconsultas: cuántas y cuánto está
 * vencido, y cuándo y cuánto vence pronto. Los totales salen de esas mismas
 * filas —los balances materializados de `REQ-PAY-008`— sin volver a la base
 * (RB-6): cuarenta apartados no son cuarenta consultas.
 *
 * Los vencimientos se cuentan con el `DueCalendar` **de ese negocio** —su
 * «hoy» y su umbral de «pronto»—, el mismo que pinta el cronograma del
 * detalle: el bloque, la fila y la alerta no pueden decir cosas distintas de
 * la misma cuota (`REQ-LAY-023` RB-1).
 *
 * ## El orden
 *
 * Primero el negocio con el vencimiento más próximo (RB-4), que es la
 * información por la que la persona entró; los que no tienen ninguno, al
 * final, por nombre.
 */
final class CustomerBalanceQuery
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Settings $settings,
    ) {}

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

            $blocks[] = $this->context->runFor($organization, function () use ($organization, $customerIds): BusinessBalance {
                $calendar = DueCalendar::of($organization, $this->settings);

                return new BusinessBalance($organization, $this->layawaysOf($customerIds, $calendar), $calendar);
            });
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
     * El balance de **una** ficha en el negocio fijado: el del perfil del
     * backoffice (`REQ-BOF-041` RB-2, `T-065`). Es el mismo cálculo que un
     * bloque del portal —la misma consulta, el mismo `BusinessBalance`—, así
     * que el negocio y el cliente no pueden ver dos cifras distintas.
     */
    public function forCustomer(Customer $customer): BusinessBalance
    {
        $organization = $this->context->organization();
        $calendar = DueCalendar::of($organization, $this->settings);

        return new BusinessBalance($organization, $this->layawaysOf([(int) $customer->getKey()], $calendar), $calendar);
    }

    /**
     * Los apartados de esas fichas, cada uno con:
     *
     *  - `overdue_installments_count` y `overdue_pending_cents`: las cuotas sin
     *    cubrir con la fecha ya pasada, y lo que falta de ellas;
     *  - `soon_due_date` y `soon_pending_cents`: la primera cuota sin cubrir
     *    que vence dentro del umbral, y lo que falta de las que vencen ese día.
     *
     * @param  list<int>  $customerIds
     * @return list<Layaway>
     */
    private function layawaysOf(array $customerIds, DueCalendar $calendar): array
    {
        $today = $calendar->today->toDateString();
        $lastSoonDay = $calendar->lastSoonDay()->toDateString();
        $pending = 'COALESCE(SUM(`installments`.`amount_cents` - `installments`.`paid_cents`), 0)';

        return Layaway::query()
            ->whereIn('customer_id', $customerIds)
            ->select('layaways.*')
            ->selectSub(self::uncovered()->where('installments.due_date', '<', $today)->selectRaw('COUNT(*)'), 'overdue_installments_count')
            ->selectSub(self::uncovered()->where('installments.due_date', '<', $today)->selectRaw($pending), 'overdue_pending_cents')
            ->selectSub(self::uncovered()->whereBetween('installments.due_date', [$today, $lastSoonDay])->selectRaw('MIN(`installments`.`due_date`)'), 'soon_due_date')
            ->selectSub(self::uncovered()->whereRaw(
                '`installments`.`due_date` = (SELECT MIN(`soon`.`due_date`) FROM `installments` AS `soon` '
                .'WHERE `soon`.`organization_id` = `installments`.`organization_id` AND `soon`.`layaway_id` = `installments`.`layaway_id` '
                .'AND `soon`.`paid_cents` < `soon`.`amount_cents` AND `soon`.`due_date` BETWEEN ? AND ?)',
                [$today, $lastSoonDay],
            )->selectRaw($pending), 'soon_pending_cents')
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    /**
     * Las cuotas sin cubrir del apartado de la fila exterior, por el global
     * scope del tenant.
     *
     * @return Builder<Installment>
     */
    private static function uncovered(): Builder
    {
        return Installment::query()
            ->whereColumn('installments.layaway_id', 'layaways.id')
            ->whereColumn('installments.paid_cents', '<', 'installments.amount_cents');
    }
}
