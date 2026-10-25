<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Collections\CollectionPreset;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Installment;
use App\Models\Layaway;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final readonly class CollectionFilters
{
    /**
     * @param  list<LayawayStatus>  $statuses
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public ?CollectionPreset $preset = null,
        public ?CarbonImmutable $dueFrom = null,
        public ?CarbonImmutable $dueTo = null,
        public array $statuses = [],
        public ?string $search = null,
        public ?int $categoryId = null,
        public ?int $catalogId = null,
    ) {
        if ($dueFrom !== null && $dueTo !== null && $dueFrom->greaterThan($dueTo)) {
            throw new InvalidArgumentException('El rango de fechas está invertido: «desde» es posterior a «hasta».');
        }
    }

    /**
     * @return Builder<Installment>|Builder<Layaway>
     */
    public function query(): Builder
    {
        return $this->preset?->listsLayaways() === true ? $this->layaways() : $this->installments();
    }

    /**
     * @return Builder<Installment>
     */
    public function installments(): Builder
    {
        return $this->apply(Installment::query())->orderBy('installments.due_date')->orderBy('installments.id');
    }

    /**
     * @param  Builder<Installment>  $query
     * @return Builder<Installment>
     */
    public function apply(Builder $query): Builder
    {
        [$today, $grace] = $this->calendar();

        $query->whereColumn('installments.paid_cents', '<', 'installments.amount_cents');

        match ($this->preset) {
            CollectionPreset::DueToday => $query->whereDate('installments.due_date', $today->toDateString()),
            CollectionPreset::DueWithin7Days => $query->whereBetween('installments.due_date', [$today->toDateString(), $today->addDays(CollectionPreset::WEEK_DAYS)->toDateString()]),
            CollectionPreset::OverdueOver15Days => $query->where('installments.due_date', '<', $today->subDays($grace + CollectionPreset::LONG_OVERDUE_DAYS)->toDateString()),
            CollectionPreset::FortnightFirstHalf => $query->whereBetween('installments.due_date', [$today->startOfMonth()->toDateString(), $today->startOfMonth()->addDays(14)->toDateString()]),
            CollectionPreset::FortnightSecondHalf => $query->whereBetween('installments.due_date', [$today->startOfMonth()->addDays(15)->toDateString(), $today->endOfMonth()->toDateString()]),
            CollectionPreset::CompletedNotDelivered, null => null,
        };

        if ($this->dueFrom !== null) {
            $query->where('installments.due_date', '>=', $this->dueFrom->toDateString());
        }

        if ($this->dueTo !== null) {
            $query->where('installments.due_date', '<=', $this->dueTo->toDateString());
        }

        return $query->whereHas('layaway', function (Builder $layaway): void {
            $layaway->whereIn('layaways.status', array_map(
                fn (LayawayStatus $status): string => $status->value,
                $this->statuses === [] ? [LayawayStatus::Active, LayawayStatus::Overdue] : $this->statuses,
            ));

            $this->narrowLayaways($layaway);
        });
    }

    /**
     * @return Builder<Layaway>
     */
    public function layaways(): Builder
    {
        $query = Layaway::query()->completedNotDelivered();

        $appZone = new DateTimeZone((string) config('app.timezone'));

        if ($this->dueFrom !== null) {
            $query->where('layaways.completed_at', '>=', $this->dueFrom->startOfDay()->setTimezone($appZone));
        }

        if ($this->dueTo !== null) {
            $query->where('layaways.completed_at', '<', $this->dueTo->addDay()->startOfDay()->setTimezone($appZone));
        }

        return $this->narrowLayaways($query)->orderBy('layaways.completed_at')->orderBy('layaways.id');
    }

    /**
     * @param  Builder<Layaway>  $layaways
     * @return Builder<Layaway>
     */
    private function narrowLayaways(Builder $layaways): Builder
    {
        if ($this->catalogId !== null) {
            $layaways->where('layaways.catalog_id', $this->catalogId);
        }

        if ($this->categoryId !== null) {
            $layaways->whereHas('product', fn (Builder $product) => $product->withTrashed()->where('products.category_id', $this->categoryId));
        }

        $search = CustomerSearch::from($this->search);

        if ($search instanceof CustomerSearch) {
            $layaways->whereHas('customer', fn (Builder $customer) => $search->apply($customer));
        }

        return $layaways;
    }

    /**
     * @return array{CarbonImmutable, int}
     */
    private function calendar(): array
    {
        $organization = app(TenantContext::class)->organization();

        return [
            CarbonImmutable::now(new DateTimeZone($organization->timezone))->startOfDay(),
            app(Settings::class)->graceDays(),
        ];
    }
}
