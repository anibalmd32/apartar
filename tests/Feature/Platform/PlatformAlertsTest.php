<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Actions\Platform\GrantSupportAccessAction;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use App\Queries\Platform\PlatformAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PlatformAlertsTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    public function test_without_anything_to_attend_there_are_no_alerts(): void
    {
        $this->asOperator();

        $this->get('/plataforma')->assertInertia(fn (AssertableInertia $page) => $page->where('platformOperator.alerts', []));
    }

    public function test_it_counts_what_needs_attention_and_links_to_it(): void
    {
        $now = CarbonImmutable::parse('2026-10-22 10:00:00');
        $this->travelTo($now);
        [$late] = $this->businessWithOwner();
        [$ending] = $this->businessWithOwner();
        $operator = $this->operator();
        $other = $this->operator();

        $this->withinOrganization($late, function () use ($now): void {
            $invoice = SubscriptionInvoice::factory()->create(['status' => InvoiceStatus::Overdue]);
            SubscriptionReceipt::factory()->create(['subscription_invoice_id' => $invoice->getKey(), 'status' => ReceiptStatus::PendingReview, 'created_at' => $now->subHours(50)]);
            SubscriptionReceipt::factory()->create(['subscription_invoice_id' => $invoice->getKey(), 'status' => ReceiptStatus::PendingReview, 'created_at' => $now->subHour()]);
        });
        $this->withinOrganization($ending, fn () => Subscription::query()->update(['status' => SubscriptionStatus::Grace, 'grace_ends_at' => $now->addDay()]));
        $this->withoutOrganization();
        app(GrantSupportAccessAction::class)->handle($operator, $late, 'Revisión de un balance que el negocio reporta mal');
        app(GrantSupportAccessAction::class)->handle($other, $ending, 'Revisión de un comprobante que el dueño reporta');
        DB::table('failed_jobs')->insert(['uuid' => 'f1', 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => $now]);

        $alerts = collect(app(PlatformAlerts::class)->for((int) $operator->getKey()))->pluck('count', 'key')->all();

        $this->assertSame(['lateReceipts' => 1, 'graceEnding' => 1, 'overdueInvoices' => 1, 'myOpenSupport' => 1, 'failedJobs' => 1], $alerts);
    }
}
