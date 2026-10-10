<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Models\Catalog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Carbon\CarbonImmutable;

/**
 * Lo que quedaría por encima del techo con otro plan, y qué pasaría con ello
 * (`REQ-SUB-005` RB-5, `REQ-SUB-008` RB-9, `T-068`).
 *
 * Es lo que la pantalla enseña **antes** de confirmar una bajada: «tienes 8
 * catálogos publicados; en Free quedará 1 y 7 se despublicarán». Y es también
 * lo que `ChangePlanAction` vuelve a calcular para exigir la confirmación
 * explícita de SP-2, así que la pantalla y la acción no pueden discrepar.
 *
 * **Compara el contador, no las tablas.** El valor materializado de
 * `usage_counters` es lo único que decide un límite (`REQ-SUB-003` RB-1), y
 * una previsualización que contara las filas podría decir «cabe» de algo que
 * después `LimitEnforcer` rechaza. La excepción es `max_products_per_catalog`,
 * que no tiene contador porque se cuenta por catálogo: ahí se cuentan las
 * filas del pivote, como hace `SyncCatalogProductsAction` al decidir.
 *
 * **Solo describe; no decide nada ni lo aplica.** Lo único que la degradación
 * hará sola es despublicar los catálogos que sobren (`REQ-SUB-008` RB-2, de
 * `T-072`); todo lo demás se conserva y solo bloquea crear más (RB-1, RB-3 a
 * RB-7). De ahí las dos consecuencias posibles de cada exceso.
 */
final readonly class PlanChangePreview
{
    /** Cuántos catálogos por encima del techo por catálogo se nombran; del resto, la cuenta. */
    public const MAX_NAMED_CATALOGS = 5;

    /** Lo único que la degradación hace sola (`REQ-SUB-008` RB-2). */
    public const CONSEQUENCE_UNPUBLISH = 'unpublish';

    /** Todo lo demás: se conserva y se bloquea crear más (`REQ-SUB-008` RB-1). */
    public const CONSEQUENCE_BLOCK_CREATION = 'block_creation';

    /**
     * @param  list<array{metric: string, used: int, limit: int, excess: int, consequence: string}>  $excesses
     * @param  list<array{name: string, slug: string, products: int}>  $catalogsOverLimit  los primeros, por nombre
     * @param  list<PlanFeature>  $lostFeatures
     * @param  list<PlanFeature>  $gainedFeatures
     */
    private function __construct(
        public Plan $target,
        public PlanChangeDirection $direction,
        public ?CarbonImmutable $effectiveAt,
        public array $excesses,
        public array $catalogsOverLimit,
        public int $catalogsOverLimitCount,
        public array $lostFeatures,
        public array $gainedFeatures,
    ) {}

    /**
     * La previsualización de pasar de la suscripción actual a `$target`, en
     * cualquier dirección.
     *
     * Subir no deja nada por encima (los techos solo crecen hacia arriba en el
     * escaparate, y si no crecieran, lo que hay ya cabe: subir no bloquea lo
     * que ya existe), así que solo dice qué funciones se ganan.
     */
    public static function of(Subscription $subscription, Plan $target): self
    {
        $direction = PlanChangeDirection::between($subscription->plan, $target);

        if ($direction === PlanChangeDirection::Downgrade) {
            return self::forDowngrade($subscription, $target);
        }

        return new self(
            target: $target,
            direction: $direction,
            effectiveAt: $direction === PlanChangeDirection::Upgrade ? CarbonImmutable::now() : null,
            excesses: [],
            catalogsOverLimit: [],
            catalogsOverLimitCount: 0,
            lostFeatures: [],
            gainedFeatures: self::featuresMissingFrom($subscription->plan, $target),
        );
    }

    /**
     * Lo que excede con un plan inferior (RB-5): cada métrica cuyo contador
     * pasa del techo del destino, los catálogos que pasan de su techo de
     * productos, y las funciones que se pierden.
     *
     * La fecha es la de la bajada: el fin del período pagado, o ahora si no
     * queda nada pagado (`ChangePlanAction::effectiveDateOf()`).
     */
    public static function forDowngrade(Subscription $subscription, Plan $target): self
    {
        $values = UsageCounter::query()->pluck('value', 'metric');
        $excesses = [];

        foreach (UsageMetric::cases() as $metric) {
            $limit = $target->limitFor($metric);
            $used = (int) ($values[$metric->value] ?? 0);

            if ($limit === null || $used <= $limit) {
                continue;
            }

            $excesses[] = [
                'metric' => $metric->value,
                'used' => $used,
                'limit' => $limit,
                'excess' => $used - $limit,
                'consequence' => $metric === UsageMetric::Catalogs ? self::CONSEQUENCE_UNPUBLISH : self::CONSEQUENCE_BLOCK_CREATION,
            ];
        }

        [$catalogs, $count] = self::catalogsAbove($target->maxProductsPerCatalog());

        return new self(
            target: $target,
            direction: PlanChangeDirection::Downgrade,
            effectiveAt: self::effectiveDateOf($subscription),
            excesses: $excesses,
            catalogsOverLimit: $catalogs,
            catalogsOverLimitCount: $count,
            lostFeatures: self::featuresMissingFrom($target, $subscription->plan),
            gainedFeatures: [],
        );
    }

    /**
     * Cuándo se aplica una bajada (RB-3): al fin del período ya pagado, o
     * ahora mismo si no queda período pagado —una suscripción abierta, ya
     * vencida o con la factura sin pagar no tiene nada que respetar—.
     *
     * Es la fecha del **cambio de plan**. La de despublicar los catálogos que
     * sobren puede ser posterior (`REQ-SUB-008` RB-2: `downgrade_grace_days`
     * desde el cambio), y sumarla es de `T-072`, que crea ese ajuste.
     *
     * «Ahora» no significa que se aplique en el acto: la aplica la pasada de
     * `T-072` (`ApplyScheduledDowngrades`), así que sigue siendo revertible
     * hasta entonces.
     */
    public static function effectiveDateOf(Subscription $subscription): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $end = $subscription->current_period_end?->toImmutable();

        // Con la factura vencida o suspendida, el período en curso **no** está
        // pagado: no hay nada que respetar, y esperar a su fin dejaría a quien
        // baja para escapar del impago atrapado un mes (`REQ-SUB-007` SP-8).
        if ($subscription->status->isDelinquent()) {
            return $now;
        }

        return $end !== null && $end->greaterThan($now) ? $end : $now;
    }

    /**
     * Indica si algo quedaría por encima de un techo (SP-2: hace falta la
     * confirmación explícita).
     */
    public function hasExcess(): bool
    {
        return $this->excesses !== [] || $this->catalogsOverLimitCount > 0;
    }

    /**
     * Forma con la que viaja a la pantalla de suscripción.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction->value,
            'effectiveAt' => $this->effectiveAt?->toIso8601String(),
            'excesses' => $this->excesses,
            'productsPerCatalog' => $this->catalogsOverLimitCount === 0 ? null : [
                'limit' => $this->target->maxProductsPerCatalog(),
                'catalogs' => $this->catalogsOverLimit,
                'count' => $this->catalogsOverLimitCount,
            ],
            'lostFeatures' => array_map(fn (PlanFeature $feature): string => $feature->value, $this->lostFeatures),
            'gainedFeatures' => array_map(fn (PlanFeature $feature): string => $feature->value, $this->gainedFeatures),
        ];
    }

    /**
     * Catálogos con más productos que `$limit`: los primeros por nombre, y
     * cuántos son en total. Sin techo, ninguno.
     *
     * @return array{0: list<array{name: string, slug: string, products: int}>, 1: int}
     */
    private static function catalogsAbove(?int $limit): array
    {
        if ($limit === null) {
            return [[], 0];
        }

        $over = Catalog::query()->has('products', '>', $limit);
        $count = (clone $over)->count();

        if ($count === 0) {
            return [[], 0];
        }

        $named = $over->withCount('products')->orderBy('name')->orderBy('id')->limit(self::MAX_NAMED_CATALOGS)->get()
            ->map(fn (Catalog $catalog): array => [
                'name' => $catalog->name,
                'slug' => $catalog->slug,
                'products' => (int) $catalog->getAttribute('products_count'),
            ])
            ->values()
            ->all();

        return [$named, $count];
    }

    /**
     * Funciones que `$has` incluye y `$lacks` no.
     *
     * @return list<PlanFeature>
     */
    private static function featuresMissingFrom(Plan $lacks, Plan $has): array
    {
        return array_values(array_filter(
            PlanFeature::cases(),
            fn (PlanFeature $feature): bool => $has->hasFeature($feature) && ! $lacks->hasFeature($feature),
        ));
    }
}
