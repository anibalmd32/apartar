<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Subscription\InvoiceKind;
use App\Domain\Subscription\InvoiceStatus;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factura de la suscripción para los tests (`T-069`). Se crea dentro de la
 * organización activa, como el resto de tablas del tenant.
 *
 * @extends Factory<SubscriptionInvoice>
 */
class SubscriptionInvoiceFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<SubscriptionInvoice> */
    protected $model = SubscriptionInvoice::class;

    /**
     * @return array<model-property<SubscriptionInvoice>, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory()->state(['price_cents' => 1_500]),
            'kind' => InvoiceKind::Renewal,
            'period_start' => now()->startOfDay(),
            'period_end' => now()->startOfDay()->addMonth(),
            'amount_cents' => 1_500,
            'paid_cents' => 0,
            'currency' => 'USD',
            'status' => InvoiceStatus::Pending,
            'due_date' => now()->toDateString(),
            'issued_at' => now(),
        ];
    }

    public function forPlan(Plan $plan): static
    {
        return $this->state(fn (): array => ['plan_id' => $plan->getKey(), 'amount_cents' => $plan->price_cents]);
    }
}
