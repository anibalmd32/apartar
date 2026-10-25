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

    private function live(): QueryBuilder
    {
        return DB::table('layaways')
            ->whereColumn('layaways.customer_id', 'customers.id')
            ->whereColumn('layaways.organization_id', 'customers.organization_id')
            ->whereIn('layaways.status', array_map(fn (LayawayStatus $status): string => $status->value, LayawayStatus::holdingStock()));
    }

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
