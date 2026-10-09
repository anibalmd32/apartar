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

/**
 * Los filtros operativos de cobranza (`REQ-BOF-042`). **La única fuente de
 * verdad de la consulta** (RB-1): la vista, la exportación (`T-070`), el envío
 * segmentado de avisos (`T-083`) y las tarjetas del panel (`REQ-BOF-044` RB-3)
 * piden aquí sus filas, y ninguna repite el filtrado.
 *
 * Las fechas son días del negocio en su zona, y la mora cuenta con **su**
 * gracia (RB-4, SP-7): se leen de la organización fijada al aplicar, así que
 * dos negocios con zonas y gracias distintas ven cada uno lo suyo. El global
 * scope hace el resto: nunca sale una cuota de otra organización (RB-8).
 *
 * Dos formas de fila: cuotas (`installments()`) para todos los presets salvo
 * `completed_not_delivered`, que es de apartados (`layaways()`) —un apartado
 * pagado sin entregar puede no tener cuotas, si se financió en cero, y una
 * lista de cuotas no lo vería—. `query()` devuelve la que toque.
 */
final readonly class CollectionFilters
{
    /**
     * @param  list<LayawayStatus>  $statuses  vacío: los que cobran (RB-5)
     *
     * @throws InvalidArgumentException si el rango está invertido (SP-2)
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
     * Las filas del filtro: cuotas, o apartados con `completed_not_delivered`.
     *
     * @return Builder<Installment>|Builder<Layaway>
     */
    public function query(): Builder
    {
        return $this->preset?->listsLayaways() === true ? $this->layaways() : $this->installments();
    }

    /**
     * Las cuotas sin cubrir del filtro, de la más próxima a la más lejana.
     *
     * @return Builder<Installment>
     */
    public function installments(): Builder
    {
        return $this->apply(Installment::query())->orderBy('installments.due_date')->orderBy('installments.id');
    }

    /**
     * Acota una consulta de cuotas (el contrato del módulo).
     *
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
            // Atrasada más de 15 días **pasada la gracia**: vence antes de hoy − gracia − 15.
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
            // RB-5 · los presets de cuotas no ven terminales; el filtro libre
            // de estados puede pedir otros.
            $layaway->whereIn('layaways.status', array_map(
                fn (LayawayStatus $status): string => $status->value,
                $this->statuses === [] ? [LayawayStatus::Active, LayawayStatus::Overdue] : $this->statuses,
            ));

            $this->narrowLayaways($layaway);
        });
    }

    /**
     * Los apartados pagados sin entregar del filtro (`REQ-LAY-010` RB-5), los
     * más antiguos primero: son los que llevan más tiempo esperando.
     *
     * @return Builder<Layaway>
     */
    public function layaways(): Builder
    {
        $query = Layaway::query()->completedNotDelivered();

        // El rango son días del negocio; `completed_at` es un instante, que se
        // guarda en la zona de la aplicación.
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
     * Lo que acota por el apartado: catálogo de origen, categoría del producto
     * y cliente (RB-3).
     *
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
     * El «hoy» del negocio y su gracia (RB-4).
     *
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
