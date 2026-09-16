<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentAccount;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Cuentas de cobro, para los tests y para `DevSeeder`.
 *
 * Como `UserFactory`, no declara `organization_id`: lo resuelve
 * `CreatesWithinTenant` al persistir.
 *
 * El método por defecto es **pago móvil**, que es el caso venezolano habitual
 * y el que exige titular y cédula (`REQ-ORG-007` RB-3); el `identifier` es
 * entonces un teléfono. Cada estado cambia método e `identifier` a la vez,
 * porque por separado producirían una cuenta que `ValidPaymentIdentifier`
 * rechazaría — y desde `T-015` esa regla existe, así que lo que se siembra
 * aquí pasa por el mismo formato que exige el formulario.
 *
 * @extends Factory<PaymentAccount>
 */
class PaymentAccountFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<PaymentAccount> */
    protected $model = PaymentAccount::class;

    /**
     * @return array<model-property<PaymentAccount>, mixed>
     */
    public function definition(): array
    {
        return [
            'method' => 'pago_movil',
            'bank' => fake()->randomElement(['Banesco', 'Mercantil', 'Provincial', 'Venezuela']),
            'holder_name' => fake()->name(),
            'holder_document' => 'V-'.fake()->numerify('########'),
            'identifier' => '0414'.fake()->numerify('#######'),
            'instructions' => null,
            'is_active' => true,
            'position' => 0,
        ];
    }

    /**
     * Transferencia bancaria: el `identifier` es el número de cuenta.
     */
    public function transferencia(): static
    {
        return $this->state(fn (): array => [
            'method' => 'transferencia',
            'identifier' => fake()->numerify('####################'),
        ]);
    }

    /**
     * Zelle: el `identifier` es un correo y no hay banco venezolano detrás.
     */
    public function zelle(): static
    {
        return $this->state(fn (): array => [
            'method' => 'zelle',
            'bank' => null,
            'identifier' => fake()->unique()->safeEmail(),
        ]);
    }

    /**
     * Efectivo en dólares: el `identifier` es la dirección de retiro.
     */
    public function efectivo(): static
    {
        return $this->state(fn (): array => [
            'method' => 'efectivo_usd',
            'bank' => null,
            'identifier' => fake()->streetAddress(),
        ]);
    }

    /**
     * Cuenta desactivada: conserva la trazabilidad de a dónde se pagó sin
     * ofrecerse ya a nuevos apartados (`REQ-ORG-007` RB-6).
     */
    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
