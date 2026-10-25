<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Subscription\IssueInvoiceAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Subscription\InvoiceKind;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\OrganizationDocumentRequiredException;
use App\Models\AuditEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\BillsSubscriptions;
use Tests\TestCase;

final class IssueInvoiceActionTest extends TestCase
{
    use BillsSubscriptions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-25 12:00:00');
        $this->setUpBilling();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_issues_a_pending_invoice_with_the_plan_price_and_audits_it(): void
    {
        $invoice = $this->issue($this->paidPlan);

        $this->assertSame(InvoiceStatus::Pending, $invoice->status);
        $this->assertSame(1_500, $invoice->amount_cents);
        $this->assertSame('USD', $invoice->currency);
        $this->assertSame('2026-11-01', $invoice->due_date->toDateString());

        $entry = $this->billed(fn (): AuditEntry => AuditEntry::query()->ofEvent(AuditEvent::SubscriptionInvoiceIssued)->sole());
        $this->assertNull($entry->causer_id, 'La emite el sistema.');
        $this->assertStringContainsString('subscriptions:issue-renewal-invoices', $entry->description);
    }

    public function test_an_issued_invoice_is_immutable(): void
    {
        $invoice = $this->issue($this->paidPlan);
        $this->paidPlan->forceFill(['price_cents' => 2_900])->save();

        $this->assertSame(1_500, $invoice->fresh()?->amount_cents);

        $immutable = [
            'amount_cents' => 1,
            'plan_id' => Plan::factory()->create()->getKey(),
            'period_start' => CarbonImmutable::now()->subYear(),
            'period_end' => CarbonImmutable::now(),
            'kind' => InvoiceKind::Manual,
            'currency' => 'VES',
            'due_date' => '2030-01-01',
            'issued_at' => CarbonImmutable::now()->subYear(),
            'organization_id' => $this->neighbourOrganization()->getKey(),
        ];
        $this->assertEqualsCanonicalizing(SubscriptionInvoice::IMMUTABLE_COLUMNS, array_keys($immutable));

        foreach ($immutable as $column => $value) {
            try {
                $this->billed(fn () => $invoice->fresh()->forceFill([$column => $value])->save());
                $this->fail("{$column} no se puede cambiar en una factura emitida.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString($column, $exception->getMessage());
            }
        }

        $this->billed(fn () => $invoice->fresh()->forceFill(['status' => InvoiceStatus::Paid, 'paid_cents' => 1_500])->save());
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_a_free_plan_is_never_invoiced(): void
    {
        $free = Plan::factory()->create(['price_cents' => 0]);

        $this->expectException(LogicException::class);

        $this->issue($free);
    }

    public function test_the_first_invoice_needs_the_tax_id(): void
    {
        $this->billedOrganization->forceFill(['document' => null])->save();

        try {
            $this->issue($this->paidPlan);
            $this->fail('Sin RIF no se emite la primera factura.');
        } catch (OrganizationDocumentRequiredException $exception) {
            $this->assertStringContainsString('RIF', $exception->getMessage());
        }

        $this->invoiceFor();
        $this->assertSame(InvoiceStatus::Pending, $this->issue($this->paidPlan)->status);
    }

    private function issue(Plan $plan): SubscriptionInvoice
    {
        return $this->billed(fn (): SubscriptionInvoice => app(IssueInvoiceAction::class)->handle(
            Subscription::query()->firstOrFail(),
            $plan,
            InvoiceKind::Renewal,
            CarbonImmutable::parse('2026-11-01 00:00:00'),
            CarbonImmutable::parse('2026-12-01 00:00:00'),
            CarbonImmutable::parse('2026-11-01'),
        ));
    }
}
