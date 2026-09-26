<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Product;
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
    protected function layawayIn(Organization $organization, LayawayStatus $status = LayawayStatus::PendingDownPayment): Layaway
    {
        return app(TenantContext::class)->runFor($organization, function () use ($organization, $status): Layaway {
            $product = Product::factory()->create();

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
                'code' => 'AP-'.Str::upper(Str::random(8)),
                'customer_id' => $customerId,
                'product_id' => $product->getKey(),
                'quantity' => 1,
                'unit_price_cents' => 20_000,
                'down_payment_cents' => 5_000,
                'installments_count' => 5,
                'frequency_days' => 15,
                'product_name_snapshot' => $product->name,
                'product_sku_snapshot' => $product->sku,
                'total_cents' => 20_000,
                'remaining_cents' => 20_000,
                'status' => LayawayStatus::PendingDownPayment,
            ])->save();

            foreach ($this->pathTo($status) as $step) {
                app(LayawayStateMachine::class)->transition($layaway, $step);
            }

            return $layaway;
        });
    }
}
