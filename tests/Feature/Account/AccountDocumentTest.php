<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class AccountDocumentTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create(['document_number' => null, 'display_name' => 'Rosa Díaz']);
    }

    public function test_an_account_without_document_must_write_it_before_using_the_portal(): void
    {
        $this->actingAs($this->account, 'account');

        $this->get(route('portal.subscriptions'))->assertRedirect(route('portal.document'));
        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.document'));
        $this->post(route('portal.claim.store'), ['document' => 'V20111222', 'code' => 'AP-ZZZZZZZZ'])->assertStatus(409);

        $this->get(route('portal.document'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/Document')
            ->where('mode', 'account')
            ->where('customerName', 'Rosa Díaz'));

        $this->post(route('portal.document.store'), ['document' => 'V-20.111.222'])->assertSessionHasNoErrors()->assertRedirect(route('portal.dashboard'));

        $this->assertSame('V20111222', DB::table('accounts')->where('id', $this->account->getKey())->value('document_number'));
        $this->get(route('portal.dashboard'))->assertOk();
    }

    public function test_the_document_alone_only_tells_how_many_businesses_have_layaways_to_claim(): void
    {
        DB::table('accounts')->where('id', $this->account->getKey())->update(['document_number' => 'V20111222']);
        $first = $this->layawayFor($this->subscribe(Organization::factory()->create(['name' => 'Bodega La Fe'])), 'V20111222');
        $this->layawayFor($this->subscribe(Organization::factory()->create(['name' => 'Ferretería Oriente'])), 'V20111222');
        $this->layawayFor($this->subscribe(Organization::factory()->create()), 'V99999999');

        $this->actingAs($this->account->refresh(), 'account');

        $this->get(route('portal.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('unclaimedBusinesses', 2)
            ->has('businesses', 0));

        $this->get(route('portal.claim'))->assertInertia(fn (AssertableInertia $page) => $page->where('document', 'V20111222'));

        $this->post(route('portal.claim.store'), ['document' => 'V20111222', 'code' => $first->code])->assertSessionHasNoErrors();

        $this->get(route('portal.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('unclaimedBusinesses', 1)
            ->has('businesses', 1)
            ->where('businesses.0.business.name', 'Bodega La Fe'));
    }

    public function test_a_record_without_document_is_claimed_with_its_code_and_takes_the_document(): void
    {
        $organization = $this->subscribe(Organization::factory()->create());
        $layaway = $this->layawayFor($organization, null);

        DB::table('accounts')->where('id', $this->account->getKey())->update(['document_number' => 'V20111222']);
        $this->actingAs($this->account->refresh(), 'account');

        $this->post(route('portal.claim.store'), ['document' => 'V-20.111.222', 'code' => $layaway->code])->assertSessionHasNoErrors()->assertRedirect(route('portal.dashboard'));

        $row = DB::table('customers')->where('id', $layaway->customer_id)->first(['document_number', 'account_id']);
        $this->assertNotNull($row);
        $this->assertSame(['V20111222', (int) $this->account->getKey()], [$row->document_number, (int) $row->account_id]);
    }

    public function test_a_record_without_document_whose_document_is_taken_is_still_linked_without_it(): void
    {
        $organization = $this->subscribe(Organization::factory()->create());
        $layaway = $this->layawayFor($organization, null);
        $this->withinOrganization($organization, fn () => Customer::factory()->create(['document_number' => 'V20111222']));
        $this->withoutOrganization();

        DB::table('accounts')->where('id', $this->account->getKey())->update(['document_number' => 'V20111222']);
        $this->actingAs($this->account->refresh(), 'account');

        $this->post(route('portal.claim.store'), ['document' => 'V20111222', 'code' => $layaway->code])->assertSessionHasNoErrors()->assertRedirect(route('portal.dashboard'));

        $row = DB::table('customers')->where('id', $layaway->customer_id)->first(['document_number', 'account_id']);
        $this->assertNotNull($row);
        $this->assertSame([null, (int) $this->account->getKey()], [$row->document_number, (int) $row->account_id]);
    }

    private function layawayFor(Organization $organization, ?string $document): Layaway
    {
        $layaway = $this->layawayIn($organization);
        DB::table('customers')->where('id', $layaway->customer_id)->update(['document_number' => $document, 'document_kind' => $document === null ? null : 'cedula']);
        $this->withoutOrganization();

        return $layaway;
    }
}
