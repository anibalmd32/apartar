<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Models\Layaway;
use App\Models\Payment;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Un pago manual de $30,00 en dólares sobre un apartado de `LayawayFactory`:
 * `rate_applied = 1`, como todo pago en USD (`REQ-FX-006` RB-4).
 *
 * Es **solo la fila**: no reparte nada entre cuotas ni mueve el balance. Lo
 * único que crea pagos de verdad es `PaymentApplier` (`T-058`), y esta
 * factory existe para los mecanismos que necesitan un pago cualquiera —el
 * `CrossTenantAccessTest` de la reasignación (`T-063`), los tests de la guarda
 * de inmutabilidad—.
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<Payment> */
    protected $model = Payment::class;

    /**
     * @return array<model-property<Payment>, mixed>
     */
    public function definition(): array
    {
        return [
            'layaway_id' => Layaway::factory(),
            'organization_id' => fn (array $attributes): mixed => DB::table('layaways')->where('id', $attributes['layaway_id'])->value('organization_id'),
            'receipt_id' => null,
            'idempotency_key' => null,
            'amount_cents_usd' => new Money(3_000),
            'declared_currency' => Currency::USD,
            'declared_amount' => '30.00',
            'rate_applied' => '1.00000000',
            'rate_source' => RateSource::Bcv,
            'rate_published_at' => now()->subHour(),
            'settled_at' => now(),
            'registered_by' => null,
            'note' => null,
        ];
    }
}
