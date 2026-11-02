<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Account\PortalContext;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Layaway\DueCalendar;
use App\Domain\Layaway\NextPayment;
use App\Domain\Payment\ReceiptsInReview;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class CustomerBalanceQuery
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Settings $settings,
        private readonly ExchangeRateService $rates,
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

                $layaways = $this->layawaysOf($customerIds, $calendar);

                return new BusinessBalance($organization, $layaways, $calendar, $this->suggestionsFor($organization, $layaways, $calendar));
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

    public function forCustomer(Customer $customer): BusinessBalance
    {
        $organization = $this->context->organization();
        $calendar = DueCalendar::of($organization, $this->settings);

        return new BusinessBalance($organization, $this->layawaysOf([(int) $customer->getKey()], $calendar), $calendar);
    }

    /**
     * @param  list<Layaway>  $layaways
     * @return array<int, array{next: ?NextPayment, review: ReceiptsInReview}>
     */
    private function suggestionsFor(Organization $organization, array $layaways, DueCalendar $calendar): array
    {
        $active = array_values(array_filter($layaways, fn (Layaway $layaway): bool => ! $layaway->status->isTerminal()));
        (new Collection($active))->load('installments');
        $reviews = ReceiptsInReview::ofLayaways($active, $organization, $this->rates);
        $suggestions = [];

        foreach ($active as $layaway) {
            $review = $reviews[(int) $layaway->getKey()];
            $suggestions[(int) $layaway->getKey()] = [
                'next' => NextPayment::for($layaway, $layaway->installments->values()->all(), $calendar->today, $review->amount),
                'review' => $review,
            ];
        }

        return $suggestions;
    }

    /**
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
            ->selectSub(self::uncovered()->where('installments.due_date', '<', $today)->selectRaw('MIN(`installments`.`due_date`)'), 'oldest_overdue_date')
            ->selectSub(self::uncovered()->whereColumn('installments.due_date', 'layaways.next_due_date')->selectRaw($pending), 'next_pending_cents')
            ->with('product.media')
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    /**
     * @return Builder<Installment>
     */
    private static function uncovered(): Builder
    {
        return Installment::query()
            ->whereColumn('installments.layaway_id', 'layaways.id')
            ->whereColumn('installments.paid_cents', '<', 'installments.amount_cents');
    }
}
