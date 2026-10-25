<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Layaway\LayawayStatus;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PortalHistoryIsolationTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, ReusesMigratedDatabase;

    public function test_the_history_never_shows_what_the_same_person_has_with_another_business(): void
    {
        $ours = $this->subscribe(Organization::factory()->create());
        $layaway = $this->layawayIn($ours, LayawayStatus::Active);
        $receipt = $this->receiptFor($layaway);

        $theirs = $this->subscribe($this->neighbourOrganization());
        $elsewhere = $this->layawayIn($theirs, LayawayStatus::Active);
        $document = DB::table('customers')->where('id', $layaway->customer_id)->value('document_number');
        DB::table('customers')->where('id', $elsewhere->customer_id)->update(['document_number' => $document]);
        $this->receiptFor($elsewhere);
        $this->receiptFor($elsewhere);
        DB::table('payments')->insert([
            'organization_id' => $theirs->getKey(), 'layaway_id' => $elsewhere->getKey(), 'amount_cents_usd' => 3_000,
            'unallocated_cents' => 0, 'declared_currency' => 'USD', 'declared_amount' => '30.00', 'rate_applied' => '1.00000000',
            'rate_source' => 'bcv', 'settled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $customer = $this->withinOrganization($ours, fn (): Customer => Customer::query()->findOrFail($layaway->customer_id));
        $this->withoutOrganization();

        $this->actingAs($customer, 'customer')
            ->get(route('portal.layaways.show', ['code' => $layaway->code]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('receipts', 1)
                ->where('receipts.0.id', pathinfo($receipt->file_path, PATHINFO_FILENAME))
                ->has('payments', 0));
    }
}
