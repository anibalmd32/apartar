<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Actions\Layaway\ReserveStockAction;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Money\Money;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Product;
use App\Support\LayawayCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Siembra apartados para los tests de la máquina de estados (`T-042`).
 *
 * Hasta `T-045` no hay `CreateLayawayAction` ni *factory* de `Layaway`, y
 * hasta `T-043` no hay modelo `Customer`: la ficha se inserta con
 * `DB::table()` —es una fila que el test necesita, no una consulta del
 * tenant— y el apartado se crea **en `pending_down_payment`**, que es el
 * único estado en el que la guarda del modelo deja nacer uno.
 *
 * **Los demás estados se alcanzan con la propia máquina**, recorriendo el
 * camino más corto de RB-1. No hay atajo por el *query builder*: un apartado
 * `completed` de test es uno que pasó por `active`, igual que uno de verdad.
 *
 * **Y reserva su unidad** con `ReserveStockAction` (`T-044`) y **sube el cupo
 * del plan** (`T-045`), como `CreateLayawayAction`: sin lo primero, expirar o
 * cancelar un apartado sembrado devolvería al stock una unidad que nunca salió
 * de él; sin lo segundo, liberaría cupo que nunca se contó.
 *
 * Sigue sin pasar por `CreateLayawayAction` a propósito: aquí lo que se quiere
 * es un apartado en un estado concreto, sin catálogo ni ficha de verdad ni sus
 * precondiciones de por medio.
 */
trait CreatesLayaways
{
    /**
     * El camino más corto desde `pending_down_payment` hasta cada estado.
     *
     * @return list<LayawayStatus>
     */
    protected function pathTo(LayawayStatus $status): array
    {
        return match ($status) {
            LayawayStatus::PendingDownPayment => [],
            LayawayStatus::Active => [LayawayStatus::Active],
            LayawayStatus::Overdue => [LayawayStatus::Active, LayawayStatus::Overdue],
            LayawayStatus::Completed => [LayawayStatus::Active, LayawayStatus::Completed],
            LayawayStatus::Cancelled => [LayawayStatus::Cancelled],
            LayawayStatus::Expired => [LayawayStatus::Expired],
        };
    }

    /**
     * Un apartado de esa organización, llevado hasta `$status`.
     */
    protected function layawayIn(
        Organization $organization,
        LayawayStatus $status = LayawayStatus::PendingDownPayment,
        ?Product $product = null,
        int $quantity = 1,
        ?LayawayTerms $terms = null,
    ): Layaway {
        // Las condiciones por defecto: $200 con $50 de inicial por unidad, 5
        // cuotas cada 15 días —el *happy path* de `REQ-LAY-004`—. Con
        // `$terms`, las suyas, y la cantidad sale de ellas.
        $terms ??= new LayawayTerms(new Money(20_000), new Money(5_000), $quantity, 5, 15);

        return app(TenantContext::class)->runFor($organization, fn (): Layaway => DB::transaction(function () use ($organization, $status, $product, $terms): Layaway {
            $quantity = $terms->quantity;
            $product ??= Product::factory()->create();

            app(ReserveStockAction::class)->handle($product, $quantity);

            // Y sube el cupo del plan, como `CreateLayawayAction` (`T-045`): sin
            // esto, cada liberación de un apartado sembrado registraba
            // `usage_counter.below_zero`, y desde `T-045` ese aviso hace fallar
            // el test (`FailsOnCounterDrift`).
            app(LimitEnforcer::class)->adjust(UsageMetric::ActiveLayaways, 1);

            $customerId = DB::table('customers')->insertGetId([
                'organization_id' => $organization->getKey(),
                'document_number' => 'V'.random_int(10_000_000, 99_999_999),
                'document_kind' => 'cedula',
                'full_name' => 'Cliente de prueba',
                'phone' => '+584141234567',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $layaway = new Layaway;
            $layaway->forceFill([
                'code' => LayawayCode::generate(),
                'idempotency_key' => (string) Str::uuid(),
                'customer_id' => $customerId,
                'product_id' => $product->getKey(),
                'quantity' => $quantity,
                'unit_price_cents' => $terms->unitPrice,
                'down_payment_cents' => $terms->downPayment,
                'installments_count' => $terms->installmentsCount,
                'frequency_days' => $terms->frequencyDays,
                'product_name_snapshot' => $product->name,
                'product_sku_snapshot' => $product->sku,
                'total_cents' => $terms->total(),
                'remaining_cents' => $terms->total(),
                'status' => LayawayStatus::PendingDownPayment,
            ])->save();

            foreach ($this->pathTo($status) as $step) {
                app(LayawayStateMachine::class)->transition($layaway, $step);
            }

            return $layaway;
        }));
    }
}
