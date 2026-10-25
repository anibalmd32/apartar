<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Layaway\LayawayStatus;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

final class ManualPaymentIsolationTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    public function test_the_neighbour_layaway_and_receipt_are_not_found_and_nothing_is_written(): void
    {
        $theirs = $this->subscribe($this->neighbourOrganization());
        $layaway = $this->layawayIn($theirs, LayawayStatus::Active);
        $receipt = $this->receiptFor($layaway);

        $ours = $this->subscribe(Organization::factory()->create());
        $intruder = $this->withinOrganization($ours, fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();
        $this->actingAs($intruder);

        $this->post(route('layaways.payments.store', $layaway), $this->payload())->assertNotFound();
        $this->getJson(route('layaways.payments.preview', [$layaway, 'amount' => '30.00', 'currency' => 'USD']))->assertNotFound();
        $this->post(route('receipts.reject', $receipt), ['code' => 'not_legible', 'reason' => 'La imagen está cortada.'])->assertNotFound();

        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame('pending_review', DB::table('receipts')->where('id', $receipt->getKey())->value('status'));
    }

    public function test_the_neighbour_payment_account_does_not_exist_here(): void
    {
        $ours = $this->subscribe(Organization::factory()->create());
        $member = $this->withinOrganization($ours, fn (): User => User::factory()->create());
        $layaway = $this->layawayIn($ours);
        $foreign = $this->withinOrganization($this->neighbourOrganization(), fn (): PaymentAccount => PaymentAccount::factory()->create());
        $this->withoutOrganization();

        $this->actingAs($member)
            ->post(route('layaways.payments.store', $layaway), $this->payload(['payment_account' => (string) $foreign->getKey(), 'amount' => '50.00']))
            ->assertSessionHasErrors(['payment_account' => 'Esa cuenta de cobro no es de tu negocio.']);

        $this->assertSame(0, DB::table('payments')->count());
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'method' => 'efectivo_usd',
            'amount' => '30.00',
            'currency' => 'USD',
            'paid_at' => now()->toDateString(),
            'note' => 'Pago en efectivo recibido en tienda.',
            'idempotency_key' => (string) Str::uuid(),
            ...$overrides,
        ];
    }
}
