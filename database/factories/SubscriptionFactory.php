<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Subscription\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Suscripciones para los tests.
 *
 * Como toda factory de una tabla del tenant, siembra dentro de la organización
 * activa (`CreatesWithinTenant`). Si no hay plan indicado crea uno propio: un
 * test que solo necesita «una suscripción» no tiene por qué saber de planes.
 *
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<Subscription> */
    protected $model = Subscription::class;

    /**
     * @return array<model-property<Subscription>, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'started_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => null,
            'grace_ends_at' => null,
            'cancelled_at' => null,
        ];
    }

    /**
     * Suscripción de pago, con su período cerrado.
     */
    public function paid(): static
    {
        return $this->state(fn (): array => [
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->startOfMonth()->addMonth(),
        ]);
    }

    public function forPlan(Plan $plan): static
    {
        return $this->state(fn (): array => ['plan_id' => $plan->getKey()]);
    }

    /**
     * Factura vencida dentro de la gracia (`REQ-SUB-007` RB-1).
     */
    public function pastDue(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::PastDue,
            'grace_ends_at' => now()->addDays(7),
        ]);
    }

    /**
     * Gracia agotada: el equipo deja de crear, el cliente final no pierde nada
     * (`REQ-SUB-007` RB-3).
     */
    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => SubscriptionStatus::Suspended]);
    }
}
