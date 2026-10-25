<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Subscription\InvoiceKind;
use App\Domain\Subscription\InvoiceStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;

trait BillsSubscriptions
{
    protected Organization $billedOrganization;

    protected User $billedOwner;

    protected Plan $paidPlan;

    protected function setUpBilling(): void
    {
        $this->paidPlan = Plan::factory()->withEveryFeature()->create(['code' => 'pro', 'name' => 'Pro', 'position' => 2, 'price_cents' => 1_500]);
        $this->billedOrganization = Organization::factory()->create(['timezone' => 'America/Caracas']);

        $this->billedOwner = $this->withinOrganization($this->billedOrganization, function (): User {
            Subscription::factory()->forPlan($this->paidPlan)->create([
                'current_period_start' => CarbonImmutable::parse('2026-10-01 00:00:00'),
                'current_period_end' => CarbonImmutable::parse('2026-11-01 00:00:00'),
            ]);

            return User::factory()->owner()->create();
        });
        $this->recalculateUsage($this->billedOrganization);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function invoiceFor(array $attributes = []): SubscriptionInvoice
    {
        return $this->withinOrganization($this->billedOrganization, function () use ($attributes): SubscriptionInvoice {
            $invoice = new SubscriptionInvoice;
            $invoice->forceFill($attributes + [
                'organization_id' => $this->billedOrganization->getKey(),
                'plan_id' => $this->paidPlan->getKey(),
                'kind' => InvoiceKind::Renewal,
                'period_start' => CarbonImmutable::parse('2026-11-01 00:00:00'),
                'period_end' => CarbonImmutable::parse('2026-12-01 00:00:00'),
                'amount_cents' => 1_500,
                'paid_cents' => 0,
                'currency' => 'USD',
                'status' => InvoiceStatus::Pending,
                'due_date' => '2026-11-01',
                'issued_at' => CarbonImmutable::now(),
            ])->save();

            return $invoice;
        });
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    protected function billed(\Closure $callback): mixed
    {
        return $this->withinOrganization($this->billedOrganization, $callback);
    }
}
