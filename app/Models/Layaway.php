<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Money\Money;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\LayawayFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Un apartado (`REQ-LAY-001`, `REQ-LAY-002`, `REQ-LAY-005`).
 *
 * Llegó en su forma mínima con `T-042` —la tabla, el tenant y los *casts* del
 * estado y de las fechas— y `T-045` le puso lo que usa la creación: los
 * *casts* de dinero, las relaciones, `terms()` y la guarda del snapshot.
 *
 * ## Las condiciones se congelan al crear, y no se vuelven a tocar
 *
 * `REQ-LAY-002` RB-1: a partir de la creación **el apartado no vuelve a leer
 * `products` para nada financiero**. `terms()` reconstruye `LayawayTerms`
 * **desde la fila** —precio y inicial por unidad, cantidad, cuotas y
 * frecuencia— y es la única fuente de las cifras del apartado. Y RB-8: ninguna
 * acción modifica esas columnas después, así que guardar un apartado con
 * cualquiera de ellas cambiada **lanza** (`SNAPSHOT_COLUMNS`). Subir el precio
 * del producto no tiene por dónde llegar aquí.
 *
 * ## El estado solo lo escribe la máquina
 *
 * `REQ-LAY-005` RB-2: **ninguna** escritura de estado ocurre fuera de
 * `LayawayStateMachine::transition()`. Un `$layaway->status = …; save()` en
 * cualquier acción convertiría la matriz de RB-1 en una sugerencia, y el día
 * que apareciera un `completed` con cuotas pendientes no habría dónde mirar.
 * Por eso no está prohibido: **está impedido**, en dos capas.
 *
 *  - **Aquí**: guardar un apartado con el estado cambiado **lanza**, y crearlo
 *    en un estado que no sea `pending_down_payment` también
 *    (`REQ-LAY-001` RB-7). Cubre el `save()`, el `update()` y el `forceFill()`
 *    de un modelo, que es como se escribe casi todo en este proyecto.
 *  - **En `LayawayStatusWritesTest`**: lo que no pasa por un modelo —un
 *    `update()` del *query builder*, un `DB::table('layaways')`— no dispara
 *    eventos, así que lo caza un test de arquitectura que recorre `app/` y
 *    solo admite la escritura del estado en la máquina.
 *
 * La máquina escribe por el *query builder* precisamente por eso: es la única
 * escritura que no pasa por esta guarda, y la única que el test de
 * arquitectura admite.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $code `AP-` y ocho caracteres, único global (`REQ-LAY-001` RB-9)
 * @property string $idempotency_key la clave del formulario (RB-12), única por organización
 * @property int $customer_id
 * @property int $product_id informativo: el apartado no vuelve a leer el producto (`REQ-LAY-002` RB-6)
 * @property int|null $catalog_id informativo (`REQ-LAY-002` RB-7)
 * @property int $quantity
 * @property Money $unit_price_cents por unidad, congelado
 * @property Money $down_payment_cents por unidad, congelado
 * @property int $installments_count no se multiplica por la cantidad (`REQ-LAY-002` RB-3)
 * @property int $frequency_days
 * @property string $product_name_snapshot
 * @property string $product_sku_snapshot
 * @property Money $total_cents `unit_price × quantity`, congelado
 * @property Money $paid_cents solo lo escribe `LayawayBalanceRecalculator` (`T-057`)
 * @property Money $remaining_cents ídem; nace igual a `total_cents`
 * @property LayawayStatus $status solo lo escribe `LayawayStateMachine`
 * @property \Illuminate\Support\Carbon|null $expires_at plazo de la inicial, no el momento en que expiró
 * @property \Illuminate\Support\Carbon|null $extended_at la prórroga única de `REQ-LAY-006` RB-3
 * @property \Illuminate\Support\Carbon|null $activated_at salida de `pending_down_payment`; no la reescribe la vuelta de la mora
 * @property \Illuminate\Support\Carbon|null $next_due_date un día del calendario de la organización
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property \Illuminate\Support\Carbon|null $stock_released_at
 */
class Layaway extends Model
{
    /** @use HasFactory<LayawayFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * Lo que se escribe una vez al crear y ya no se toca (`REQ-LAY-002` RB-1,
     * RB-8): las condiciones congeladas, lo que identifica al apartado y el
     * producto del que salieron. `customer_id` no está porque la fusión de
     * fichas lo mueve (`REQ-IDN-006`); `catalog_id` tampoco, porque lo anula
     * la base si el catálogo desaparece (SP-4).
     *
     * @var list<string>
     */
    public const SNAPSHOT_COLUMNS = [
        'code',
        'idempotency_key',
        'product_id',
        'quantity',
        'unit_price_cents',
        'down_payment_cents',
        'installments_count',
        'frequency_days',
        'product_name_snapshot',
        'product_sku_snapshot',
        'total_cents',
    ];

    protected static function booted(): void
    {
        static::saving(static function (Layaway $layaway): void {
            $status = $layaway->getAttribute('status');

            if (! $layaway->exists) {
                if ($status !== null && $status !== LayawayStatus::PendingDownPayment) {
                    throw new LogicException(sprintf(
                        'Un apartado nace en «pending_down_payment» (REQ-LAY-001 RB-7) y se intentó crear en «%s». '
                        .'Los demás estados se alcanzan con LayawayStateMachine::transition().',
                        $status instanceof LayawayStatus ? $status->value : (string) $status,
                    ));
                }

                return;
            }

            if ($layaway->isDirty('status')) {
                throw new LogicException(
                    'El estado de un apartado solo lo escribe LayawayStateMachine::transition() (REQ-LAY-005 RB-2): '
                    .'la máquina valida la matriz, escribe la marca de tiempo, ajusta el cupo del plan y emite el '
                    .'evento en la misma transacción. Guardarlo a mano se salta las cuatro cosas.'
                );
            }

            $changed = array_values(array_filter(self::SNAPSHOT_COLUMNS, fn (string $column): bool => $layaway->isDirty($column)));

            if ($changed !== []) {
                throw new LogicException(sprintf(
                    'Las condiciones de un apartado se congelan al crearlo y no se vuelven a tocar (REQ-LAY-002 RB-8), '
                    .'y se intentó cambiar: %s. No hay recálculo de un apartado: se cancela y se crea otro (SP-2).',
                    implode(', ', $changed),
                ));
            }
        });
    }

    /**
     * Las condiciones del apartado, reconstruidas **desde la fila**
     * (`REQ-LAY-002` RB-1).
     *
     * Es la única fuente del total, la inicial y el cronograma: de aquí salen
     * `total()`, `totalDownPayment()` y el plan que `T-046` reparte. Nunca del
     * producto, que pudo cambiar de precio o archivarse después.
     */
    public function terms(): LayawayTerms
    {
        return new LayawayTerms(
            unitPrice: $this->unit_price_cents,
            downPayment: $this->down_payment_cents,
            quantity: $this->quantity,
            installmentsCount: $this->installments_count,
            frequencyDays: $this->frequency_days,
        );
    }

    /**
     * El cronograma materializado, en orden (`REQ-LAY-004`). Vacío hasta la
     * activación (RB-1), y vacío para siempre si no hay nada que financiar
     * (SP-2).
     *
     * @return HasMany<Installment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('number');
    }

    /**
     * Los pagados que el negocio todavía no entregó: su cola de trabajo de
     * mostrador (`REQ-LAY-010` RB-5).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCompletedNotDelivered(Builder $query): Builder
    {
        return $query->where('status', LayawayStatus::Completed)->whereNull('delivered_at');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Para navegar, no para calcular (`REQ-LAY-002` RB-6).
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * El catálogo de origen; informativo, y puede no existir ya (RB-7, SP-4).
     *
     * @return BelongsTo<Catalog, $this>
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => LayawayStatus::class,
            'quantity' => 'integer',
            'unit_price_cents' => MoneyCast::class,
            'down_payment_cents' => MoneyCast::class,
            'installments_count' => 'integer',
            'frequency_days' => 'integer',
            'total_cents' => MoneyCast::class,
            'paid_cents' => MoneyCast::class,
            'remaining_cents' => MoneyCast::class,
            'expires_at' => 'datetime',
            'extended_at' => 'datetime',
            'activated_at' => 'datetime',
            // Un día del calendario de la organización, no un instante
            // (`T-039`): `DATE` en la base y `date` aquí.
            'next_due_date' => 'date',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'delivered_at' => 'datetime',
            'stock_released_at' => 'datetime',
        ];
    }
}
