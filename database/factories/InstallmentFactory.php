<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Money\Money;
use App\Models\Installment;
use App\Models\Layaway;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * La primera cuota de un apartado de `LayawayFactory`, de $30 y a quince días.
 *
 * Como esa factory, existe para los mecanismos que necesitan **una cuota
 * cualquiera** de una organización (`CrossTenantAccessTest`, `T-053`). No es
 * un cronograma: el de verdad lo genera `ActivateLayawayAction`, con sus cinco
 * cuotas sumando el financiado al céntimo.
 *
 * La organización es la del apartado, leída de su fila: sin contexto,
 * `CreatesWithinTenant` crearía una nueva y la cuota quedaría en un negocio y
 * su apartado en otro.
 *
 * @extends Factory<Installment>
 */
class InstallmentFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<Installment> */
    protected $model = Installment::class;

    /**
     * @return array<model-property<Installment>, mixed>
     */
    public function definition(): array
    {
        return [
            'layaway_id' => Layaway::factory(),
            'organization_id' => fn (array $attributes): mixed => DB::table('layaways')->where('id', $attributes['layaway_id'])->value('organization_id'),
            'number' => 1,
            'due_date' => now()->addDays(15)->toDateString(),
            'amount_cents' => new Money(3_000),
            'paid_cents' => new Money(0),
        ];
    }
}
