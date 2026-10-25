<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Domain\Account\ClaimVerificationFailedException;
use App\Domain\Account\CustomerAlreadyLinkedException;
use App\Domain\Auditing\AuditEvent;
use App\Http\Controllers\Portal\ClaimController;
use App\Http\Middleware\EnsureAccountSession;
use App\Http\Requests\Portal\ClaimCustomerRequest;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ClaimTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private Account $account;

    private Layaway $layaway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create(['name' => 'Ferretería Oriente']));
        $this->account = Account::factory()->create();
        $this->layaway = $this->layawayIn($this->organization);
        DB::table('customers')->where('id', $this->layaway->customer_id)->update(['document_number' => 'V12345678']);
        $this->withoutOrganization();
    }

    public function test_claiming_links_audits_and_shows_the_layaways(): void
    {
        $this->actingAs($this->account, 'account')
            ->post(route('portal.claim.store'), ['document' => 'V-12.345.678', 'code' => strtolower($this->layaway->code)])
            ->assertRedirect(route('portal.dashboard'))
            ->assertSessionHas('status', sprintf(ClaimController::CLAIMED_MESSAGE, 'Ferretería Oriente'));

        $this->assertSame(1, $this->withinOrganization($this->organization, fn (): int => AuditEntry::query()->ofEvent(AuditEvent::AccountLinked)->count()));
        $this->withoutOrganization();

        $this->get(route('portal.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('businesses', 1)
            ->where('businesses.0.business.name', 'Ferretería Oriente'));
    }

    public function test_failures_are_generic_and_the_sixth_attempt_is_throttled(): void
    {
        $this->actingAs($this->account, 'account');

        for ($attempt = 1; $attempt <= ClaimCustomerRequest::MAX_ATTEMPTS; $attempt++) {
            $this->postJson(route('portal.claim.store'), ['document' => 'V12345678', 'code' => 'AP-NOEXISTE'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['code' => ClaimVerificationFailedException::MESSAGE]);
        }

        $this->postJson(route('portal.claim.store'), ['document' => 'V12345678', 'code' => $this->layaway->code])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->assertNull(DB::table('customers')->where('id', $this->layaway->customer_id)->value('account_id'));
    }

    public function test_the_limit_is_also_per_ip(): void
    {
        $this->actingAs($this->account, 'account');

        for ($attempt = 1; $attempt <= ClaimCustomerRequest::MAX_ATTEMPTS; $attempt++) {
            $this->postJson(route('portal.claim.store'), ['document' => 'V12345678', 'code' => 'AP-NOEXISTE']);
        }

        $this->actingAs(Account::factory()->create(), 'account')
            ->postJson(route('portal.claim.store'), ['document' => 'V12345678', 'code' => 'AP-NOEXISTE'])
            ->assertStatus(429);
    }

    public function test_a_record_of_another_account_answers_422_pointing_to_the_business(): void
    {
        DB::table('customers')->where('id', $this->layaway->customer_id)->update(['account_id' => Account::factory()->create()->getKey()]);

        $this->actingAs($this->account, 'account')
            ->postJson(route('portal.claim.store'), ['document' => 'V12345678', 'code' => $this->layaway->code])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code' => CustomerAlreadyLinkedException::TO_ANOTHER_ACCOUNT]);
    }

    public function test_claiming_requires_an_account(): void
    {
        $this->get(route('portal.claim'))->assertRedirect(route('account.google.redirect'));

        $customer = Customer::query()->withoutGlobalScopes()->findOrFail($this->layaway->customer_id);
        $this->actingAs($customer, 'customer')
            ->get(route('portal.claim'))
            ->assertRedirect(route('portal.dashboard'))
            ->assertSessionHas('status', EnsureAccountSession::CONNECT_GOOGLE_MESSAGE);

        $this->actingAs($this->account, 'account')
            ->get(route('portal.claim'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('portal/Claim')->where('storeUrl', route('portal.claim.store')));
    }
}
