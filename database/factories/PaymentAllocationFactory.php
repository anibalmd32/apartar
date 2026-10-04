<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Money\Money;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Una asignación de $30,00 de un pago de `PaymentFactory` a una cuota **del
 * mismo apartado** (`REQ-PAY-009` RB-3: el reparto nunca cruza apartados).
 *
 * Como `PaymentFactory`, es la fila sola: no mueve `paid_cents` de la cuota.
 *
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<PaymentAllocation> */
    protected $model = PaymentAllocation::class;

    /**
     * @return array<model-property<PaymentAllocation>, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'organization_id' => fn (array $attributes): mixed => DB::table('payments')->where('id', $attributes['payment_id'])->value('organization_id'),
            'installment_id' => fn (array $attributes): mixed => Installment::factory()->createOne([
                'layaway_id' => DB::table('payments')->where('id', $attributes['payment_id'])->value('layaway_id'),
            ])->getKey(),
            'amount_cents' => new Money(3_000),
        ];
    }
}
