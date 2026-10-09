<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Layaway\DueCalendar;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * El directorio de clientes de un negocio (`REQ-BOF-040`).
 *
 * Una consulta sobre `customers` con los agregados de sus apartados **vivos**
 * como subconsultas (RB-3): apartados activos, comprometido, pagado,
 * pendiente —de los balances materializados (`REQ-PAY-008`)—, cuotas vencidas
 * y próximo vencimiento. Sin `N+1`: una sola consulta por página, sea cual
 * sea el número de filas.
 *
 * «Vencida» y «próximo vencimiento» se cuentan **como en el portal**
 * (`CustomerBalanceQuery`, `REQ-LAY-020` RB-6): una cuota sin cubrir con
 * vencimiento anterior al «hoy» del negocio, en su zona. Así el directorio, el
 * perfil y el portal no pueden discrepar sobre el mismo cliente.
 *
 * **«En mora» es otra cosa** (`REQ-LAY-007` RB-1): vencida y pasada la gracia
 * del negocio. Es la que filtra «con mora» (RB-4), la que se destaca (RB-5)
 * y la que cuenta la tarjeta del panel: una cuota de ayer, dentro de la
 * gracia, está vencida pero el cliente no está en mora.
 *
 * El orden por defecto es el de la cobranza (RB-6): más cuotas vencidas
 * primero, y después el vencimiento más cercano; los que no tienen ninguno, al
 * final. El global scope deja fuera las fichas de otras organizaciones (RB-7,
 * SP-8).
 */
final class CustomerDirectoryQuery
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Settings $settings,
    ) {}

    /**
     * @return Builder<Customer>
     */
    public function search(CustomerDirectoryFilters $filters): Builder
    {
        $calendar = DueCalendar::of($this->context->organization(), $this->settings);
        $today = $calendar->today->toDateString();
        // En mora (`REQ-LAY-007` RB-1): vencida y pasada **la gracia del negocio**.
        $arrearsBefore = $calendar->today->subDays($this->settings->graceDays())->toDateString();

        $query = Customer::query()
            ->select('customers.*')
            ->selectSub($this->live()->selectRaw('COUNT(*)'), 'active_layaways')
            ->selectSub($this->live()->selectRaw('COALESCE(SUM(layaways.total_cents), 0)'), 'committed_cents')
            ->selectSub($this->live()->selectRaw('COALESCE(SUM(layaways.paid_cents), 0)'), 'paid_cents')
            ->selectSub($this->live()->selectRaw('COALESCE(SUM(layaways.remaining_cents), 0)'), 'remaining_cents')
            ->selectSub($this->uncovered()->where('installments.due_date', '<', $today)->selectRaw('COUNT(*)'), 'overdue_installments')
            ->selectSub($this->uncovered()->where('installments.due_date', '<', $arrearsBefore)->selectRaw('COUNT(*) > 0'), 'in_arrears')
            ->selectSub($this->uncovered()->selectRaw('MIN(installments.due_date)'), 'next_due_date');

        $search = CustomerSearch::from($filters->search);

        if ($search instanceof CustomerSearch) {
            $search->apply($query);
        }

        if ($filters->overdue) {
            $query->whereExists($this->uncovered()->where('installments.due_date', '<', $arrearsBefore));
        }

        if ($filters->dueSoon) {
            $query->whereExists($this->uncovered()->whereBetween('installments.due_date', [$today, $calendar->lastSoonDay()->toDateString()]));
        }

        if ($filters->withoutActive) {
            $query->whereNotExists($this->live());
        }

        // El rango es del **próximo** vencimiento, el que pinta la fila: no
        // basta con tener alguna cuota dentro.
        $next = $this->uncovered()->selectRaw('MIN(installments.due_date)');

        if ($filters->nextDueFrom !== null) {
            $query->whereRaw('('.$next->toSql().') >= ?', [...$next->getBindings(), $filters->nextDueFrom->toDateString()]);
        }

        if ($filters->nextDueTo !== null) {
            $query->whereRaw('('.$next->toSql().') <= ?', [...$next->getBindings(), $filters->nextDueTo->toDateString()]);
        }

        if ($filters->catalogId !== null) {
            $query->whereExists(DB::table('layaways')
                ->whereColumn('layaways.customer_id', 'customers.id')
                ->whereColumn('layaways.organization_id', 'customers.organization_id')
                ->where('layaways.catalog_id', $filters->catalogId));
        }

        return $query
            ->orderByDesc('overdue_installments')
            ->orderByRaw('next_due_date IS NULL')
            ->orderBy('next_due_date')
            ->orderBy('customers.full_name')
            ->orderBy('customers.id');
    }

    /**
     * Los apartados vivos de la ficha de la fila: los que no son terminales.
     */
    private function live(): QueryBuilder
    {
        return DB::table('layaways')
            ->whereColumn('layaways.customer_id', 'customers.id')
            ->whereColumn('layaways.organization_id', 'customers.organization_id')
            ->whereIn('layaways.status', array_map(fn (LayawayStatus $status): string => $status->value, LayawayStatus::holdingStock()));
    }

    /**
     * Las cuotas sin cubrir de los apartados vivos de la ficha de la fila.
     */
    private function uncovered(): QueryBuilder
    {
        return DB::table('installments')
            ->join('layaways', 'layaways.id', '=', 'installments.layaway_id')
            ->whereColumn('layaways.customer_id', 'customers.id')
            ->whereColumn('layaways.organization_id', 'customers.organization_id')
            ->whereColumn('installments.organization_id', 'customers.organization_id')
            ->whereIn('layaways.status', array_map(fn (LayawayStatus $status): string => $status->value, LayawayStatus::holdingStock()))
            ->whereColumn('installments.paid_cents', '<', 'installments.amount_cents');
    }
}
