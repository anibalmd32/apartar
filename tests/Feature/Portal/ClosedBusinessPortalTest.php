<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\UploadsReceiptFiles;
use Tests\TestCase;

final class ClosedBusinessPortalTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase, UploadsReceiptFiles;

    private Organization $organization;

    private Layaway $layaway;

    private Customer $customer;

    private PaymentAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Receipt::DISK);
        Queue::fake();
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');

        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'America/Caracas']));
        $this->layaway = $this->layawayIn($this->organization);
        $this->customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->findOrFail($this->layaway->customer_id));
        $this->account = $this->withinOrganization($this->organization, fn (): PaymentAccount => PaymentAccount::factory()->createOne());
        $this->organization->forceFill(['status' => OrganizationStatus::Closed])->save();
        $this->withoutOrganization();
        $this->actingAs($this->customer, 'customer');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_layaway_is_visible_read_only_with_the_reason(): void
    {
        $this->get(route('portal.layaways.show', ['code' => $this->layaway->code]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('layaway.code', $this->layaway->code)
                ->where('layaway.statusMessage', PaymentNotApplicableException::CLOSED_MESSAGE)
                ->where('receiptUrl', null));
    }

    public function test_the_receipt_form_is_refused(): void
    {
        $this->get(route('portal.receipts.create', ['code' => $this->layaway->code]))->assertForbidden();
    }

    public function test_a_receipt_posted_anyway_is_refused(): void
    {
        $this->post(route('portal.receipts.store', ['code' => $this->layaway->code]), [
            'paymentAccount' => $this->account->getKey(),
            'method' => 'pago_movil',
            'bank' => 'Banesco',
            'reference' => '0000000001',
            'paidAt' => '2026-10-01',
            'amount' => '850.00',
            'currency' => 'VES',
            'note' => null,
            'file' => $this->receiptImage(),
        ])->assertForbidden();

        $this->assertSame(0, $this->withinOrganization($this->organization, fn (): int => Receipt::query()->count()));
    }
}
