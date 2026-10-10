<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Subscription\PlanAllowance;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\UsageMetric;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Un plan del catálogo comercial (`REQ-SUB-001`).
 *
 * Tabla de plataforma: no lleva `organization_id` ni el trait del tenant,
 * porque los tres planes son los mismos para todos los negocios.
 *
 * **Los planes son datos, no código** (RB-1), y este modelo es lo que hace que
 * esa frase se sostenga: todo el sistema pregunta «¿cuál es el techo de esta
 * métrica?» y nadie pregunta «¿es este el plan Pro?». La diferencia se nota el
 * día que hay que subir el tope de productos de Free: con `limitFor()` es un
 * `UPDATE`; con un `if ($plan->code === 'free')` repartido por seis archivos,
 * es un despliegue y dos fuentes de verdad que ya han empezado a divergir.
 *
 * @property int $id
 * @property string $code identificador estable: `free`, `pro`, `business`
 * @property string $name
 * @property int $price_cents
 * @property string $interval
 * @property bool $is_public
 * @property int $position
 * @property int|null $max_users
 * @property int|null $max_catalogs
 * @property int|null $max_products
 * @property int|null $max_products_per_catalog
 * @property int|null $max_active_layaways
 * @property int|null $max_storage_mb
 * @property int|null $max_payment_accounts
 * @property array<string, mixed>|null $features
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'price_cents',
        'interval',
        'is_public',
        'position',
        'max_users',
        'max_catalogs',
        'max_products',
        'max_products_per_catalog',
        'max_active_layaways',
        'max_storage_mb',
        'max_payment_accounts',
        'features',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'is_public' => 'boolean',
            'position' => 'integer',
            'max_users' => 'integer',
            'max_catalogs' => 'integer',
            'max_products' => 'integer',
            'max_products_per_catalog' => 'integer',
            'max_active_layaways' => 'integer',
            'max_storage_mb' => 'integer',
            'max_payment_accounts' => 'integer',
            'features' => 'array',
        ];
    }

    /**
     * Techo de una métrica, o `null` si es ilimitada (RB-2).
     *
     * `null` es **ilimitado**, nunca «sin definir» ni cero. Quien reciba este
     * valor tiene que tratarlo como tal en todo el camino hasta la pantalla:
     * un `0` mostrado donde va «ilimitado» convierte el mejor plan en el peor
     * (SP-2).
     */
    public function limitFor(UsageMetric $metric): ?int
    {
        $limit = $this->getAttribute($metric->planColumn());

        return $limit === null ? null : (int) $limit;
    }

    /**
     * Máximo de productos que se pueden añadir a **un** catálogo.
     *
     * Es la séptima columna de límite y la única sin `UsageMetric`: se cuenta
     * por catálogo y los contadores de `usage_counters` son por organización.
     * `REQ-SUB-004` RB-3 la nombra por su columna justamente por eso.
     */
    public function maxProductsPerCatalog(): ?int
    {
        return $this->max_products_per_catalog;
    }

    /**
     * Indica si el plan incluye una función.
     *
     * Un plan sin `features` no es un error: es un plan sin funciones de pago
     * (SP-1).
     */
    public function hasFeature(PlanFeature $feature): bool
    {
        return (bool) ($this->features[$feature->value] ?? false);
    }

    /**
     * Valor concedido para una de las cifras que no son techos, o `null` si
     * no tiene límite.
     */
    public function allowance(PlanAllowance $allowance): ?int
    {
        $value = $this->features[$allowance->value] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Indica si el catálogo de este plan muestra el sello «Hecho con
     * Apartar».
     *
     * Se deriva de `branding` y no se guarda aparte (`REQ-CAT-022` RB-5): el
     * sello es exactamente lo contrario de tener marca propia, y un plan con
     * las dos cosas a la vez no es un estado que la spec admita.
     */
    public function showsPlatformBadge(): bool
    {
        return ! $this->hasFeature(PlanFeature::Branding);
    }

    /**
     * Funciones que el plan incluye, para las page props.
     *
     * @return list<PlanFeature>
     */
    public function enabledFeatures(): array
    {
        return array_values(array_filter(
            PlanFeature::cases(),
            fn (PlanFeature $feature): bool => $this->hasFeature($feature),
        ));
    }

    /**
     * Fin del período que empieza en `$start`, según el intervalo del plan
     * (`REQ-SUB-005` RB-2, `T-068`).
     *
     * Solo existe el mensual: los planes anuales están fuera de alcance
     * (`REQ-SUB-005`). Un intervalo que no se reconoce **lanza** en lugar de
     * suponer un mes: un plan anual cobrado como mensual sería una factura mal
     * emitida, y eso no se descubre mirando la pantalla.
     *
     * @throws LogicException con un intervalo que no se sabe medir
     */
    public function periodEndFrom(CarbonInterface $start): CarbonImmutable
    {
        return match ($this->interval) {
            'monthly' => $start->toImmutable()->addMonthNoOverflow(),
            default => throw new LogicException(sprintf('El plan %s tiene un intervalo «%s» que nadie sabe medir.', $this->code, $this->interval)),
        };
    }

    /**
     * Indica si este plan va **por encima** de otro en el escaparate: es lo
     * que hace de un cambio una subida o una bajada (`REQ-SUB-005` RB-2, RB-3).
     *
     * Se compara la posición, que es el orden en que `/precios` los compara, y
     * no el código (`REQ-SUB-001` RB-1). Tampoco el precio: hoy los tres
     * valen cero (RB-3b), y comparar importes diría que todo cambio es al
     * mismo plan.
     */
    public function ranksAbove(self $other): bool
    {
        return $this->position > $other->position;
    }

    /**
     * Planes que se muestran en `/precios`, en el orden en que se comparan.
     *
     * Un plan no público sigue funcionando para quien ya está dentro; solo
     * desaparece del escaparate (RB-6 y SP-3).
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)->orderBy('position');
    }
}
