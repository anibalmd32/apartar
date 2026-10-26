<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Subscription\InvoiceKind;
use App\Domain\Subscription\InvoiceStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformActivity;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Support\Auditing\PlatformAudit;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class IssueManualInvoiceActionTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-22 10:00:00');
        [$this->organization] = $this->businessWithOwner(Plan::factory()->create(['price_cents' => 3_900]));
        $this->organization->forceFill(['timezone' => 'America/Caracas'])->save();
        $this->withinOrganization($this->organization, fn () => Subscription::query()->update(['current_period_end' => '2026-11-15 04:00:00']));
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_bills_one_month_of_the_current_plan_from_the_end_of_the_period(): void
    {
        $operator = $this->asOperator();

        $this->get($this->url())->assertInertia(fn (AssertableInertia $page) => $page->where('invoiceable', true));
        $this->post($this->url().'/facturas', ['reason' => 'corto'])->assertSessionHasErrors('reason');
        $this->post($this->url().'/facturas', ['reason' => 'Renovación adelantada pedida por el dueño'])->assertSessionHasNoErrors();

        $invoice = $this->invoices()[0];
        $this->assertSame(InvoiceKind::Manual, $invoice->kind);
        $this->assertSame(InvoiceStatus::Pending, $invoice->status);
        $this->assertSame(3_900, $invoice->amount_cents);
        $this->assertSame('2026-11-15 04:00:00', $invoice->period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-15 04:00:00', $invoice->period_end->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-15', $invoice->due_date->toDateString());
        $this->assertSame(1, PlatformActivity::query()->where('action', PlatformAudit::INVOICE_ISSUED)->where('platform_user_id', $operator->getKey())->count());
        $this->get($this->url())->assertInertia(fn (AssertableInertia $page) => $page->where('invoiceable', false));
    }

    public function test_it_refuses_while_another_invoice_is_outstanding(): void
    {
        $this->withinOrganization($this->organization, fn () => SubscriptionInvoice::factory()->create(['status' => InvoiceStatus::Overdue]));
        $this->withoutOrganization();
        $this->asOperator();

        $this->post($this->url().'/facturas', ['reason' => 'Renovación adelantada pedida por el dueño'])->assertSessionHasErrors('invoice');
        $this->assertCount(1, $this->invoices());
    }

    public function test_a_free_plan_is_never_billed(): void
    {
        [$free] = $this->businessWithOwner();
        $this->asOperator();

        $this->get('/plataforma/organizaciones/'.$free->slug)->assertInertia(fn (AssertableInertia $page) => $page->where('invoiceable', false));
        $this->post('/plataforma/organizaciones/'.$free->slug.'/facturas', ['reason' => 'Renovación adelantada pedida por el dueño'])->assertSessionHasErrors('invoice');
    }

    private function url(): string
    {
        return '/plataforma/organizaciones/'.$this->organization->slug;
    }

    /**
     * @return list<SubscriptionInvoice>
     */
    private function invoices(): array
    {
        $invoices = $this->withinOrganization($this->organization, fn (): array => SubscriptionInvoice::query()->get()->all());
        $this->withoutOrganization();

        return $invoices;
    }
}
