<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Layaway\LayawayStatus;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

final class ReceiptReviewIsolationTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    public function test_a_receipt_of_another_business_is_not_found_and_nothing_is_written(): void
    {
        $theirs = $this->subscribe($this->neighbourOrganization());
        $receipt = $this->receiptFor($this->layawayIn($theirs, LayawayStatus::Active));

        $ours = $this->subscribe(Organization::factory()->create());
        $intruder = $this->withinOrganization($ours, fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();
        $this->actingAs($intruder);

        $this->get(route('receipts.show', $receipt))->assertNotFound();
        $this->getJson(route('receipts.preview', [$receipt, 'amount' => '30.00', 'currency' => 'USD']))->assertNotFound();
        $this->post(route('receipts.approve', $receipt), ['amount' => '30.00', 'currency' => 'USD'])->assertNotFound();

        $this->assertSame('pending_review', DB::table('receipts')->where('id', $receipt->getKey())->value('status'));
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_the_previous_payments_are_only_those_of_the_layaway(): void
    {
        $organization = $this->subscribe(Organization::factory()->create());
        $member = $this->withinOrganization($organization, fn (): User => User::factory()->create());
        $receipt = $this->receiptFor($this->layawayIn($organization));

        $neighbour = $this->subscribe($this->neighbourOrganization());
        $theirs = $this->receiptFor($this->layawayIn($neighbour), attributes: ['declared_amount' => '50.00', 'declared_currency' => 'USD']);
        $neighbourOwner = $this->withinOrganization($neighbour, fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();

        $this->actingAs($neighbourOwner)->post(route('receipts.approve', $theirs), ['amount' => '50.00', 'currency' => 'USD'])->assertRedirect();
        $this->assertSame(1, DB::table('payments')->count());

        $this->app['auth']->forgetGuards();
        $this->withoutOrganization();
        $this->actingAs($member)->get(route('receipts.show', $receipt))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('payments', 0));
    }

    public function test_the_binding_resolves_within_the_tenant(): void
    {
        $theirs = $this->subscribe($this->neighbourOrganization());
        $receipt = $this->receiptFor($this->layawayIn($theirs, LayawayStatus::Active));

        $this->assertNull($this->withinOrganization(
            $this->subscribe(Organization::factory()->create()),
            fn (): ?Receipt => Receipt::query()->find($receipt->getKey()),
        ));
    }
}
