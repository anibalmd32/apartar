<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domain\Payment\ReceiptStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Receipt;
use App\Support\SignedFileUrl;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PortalSessionModesTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, ReusesMigratedDatabase;

    private Organization $ferreteria;

    private Organization $boutique;

    private Account $account;

    private Layaway $tool;

    private Layaway $dress;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ferreteria = $this->subscribe(Organization::factory()->create(['name' => 'Ferretería Oriente']));
        $this->boutique = $this->subscribe(Organization::factory()->create(['name' => 'Boutique Sur']));
        $this->account = Account::factory()->create(['display_name' => 'María Pérez']);

        $this->tool = $this->layawayIn($this->ferreteria);
        $this->dress = $this->layawayIn($this->boutique);
        $this->link($this->tool);
        $this->link($this->dress);
        $this->withoutOrganization();
    }

    public function test_the_account_sees_its_linked_records_of_several_businesses(): void
    {
        $this->actingAs($this->account, 'account')
            ->get(route('portal.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('portal/Dashboard')
                ->where('session.mode', 'account')
                ->where('session.displayName', 'María Pérez')
                ->has('businesses', 2)
                ->where('totals.activeLayaways', 2)
                ->where('connectGoogleUrl', null)
                ->where('subscriptionsUrl', route('portal.subscriptions')));

        $blocks = $this->blocks();
        $this->assertSame([$this->tool->code], $blocks['Ferretería Oriente']);
        $this->assertSame([$this->dress->code], $blocks['Boutique Sur']);
    }

    public function test_the_scoped_session_sees_one_business_and_is_offered_google(): void
    {
        $this->actingAs($this->customerOf($this->tool), 'customer')
            ->get(route('portal.dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('session.mode', 'scoped')
                ->has('businesses', 1)
                ->where('totals', null)
                ->where('subscriptionsUrl', null)
                ->where('connectGoogleUrl', fn (string $url): bool => str_contains($url, '/cuenta/google/redirect')));
    }

    public function test_with_both_sessions_the_account_prevails(): void
    {
        $unlinked = $this->layawayIn($this->ferreteria, product: null);
        $this->withoutOrganization();

        $this->actingAs($this->customerOf($unlinked), 'customer')->actingAs($this->account, 'account');

        $this->get(route('portal.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('session.mode', 'account'));
        $this->assertNotContains($unlinked->code, array_merge(...array_values($this->blocks())));
        $this->get(route('portal.layaways.show', ['code' => $unlinked->code]))->assertNotFound();
    }

    public function test_an_account_without_records_is_valid(): void
    {
        $this->actingAs(Account::factory()->create(), 'account')
            ->get(route('portal.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('session.mode', 'account')->where('businesses', []));
    }

    public function test_the_account_opens_its_layaways_by_code_and_a_foreign_code_is_404(): void
    {
        $neighbour = $this->subscribe(Organization::factory()->create());
        $foreignSameBusiness = $this->layawayIn($this->ferreteria);
        $foreignOtherBusiness = $this->layawayIn($neighbour);
        $this->withoutOrganization();
        $this->actingAs($this->account, 'account');

        foreach ([$this->tool, $this->dress] as $own) {
            $this->withoutOrganization();
            $this->get(route('portal.layaways.show', ['code' => $own->code]))->assertOk();
            $this->withoutOrganization();
            $this->get(route('portal.receipts.create', ['code' => $own->code]))->assertOk();
        }

        foreach ([$foreignSameBusiness, $foreignOtherBusiness] as $foreign) {
            $this->withoutOrganization();
            $this->get(route('portal.layaways.show', ['code' => $foreign->code]))->assertNotFound();
        }

        $this->withoutOrganization();

        $this->get(route('portal.layaways.show', ['code' => 'AP-NOEXISTE']))->assertNotFound();
    }

    public function test_the_account_opens_its_receipt_files(): void
    {
        Storage::fake(Receipt::DISK);
        $own = $this->receiptWithFile($this->dress);
        $foreign = $this->receiptWithFile($this->layawayIn($this->boutique));
        $this->withoutOrganization();
        $this->actingAs($this->account, 'account');

        $this->get(SignedFileUrl::forCustomer($own))->assertOk();
        $this->get(SignedFileUrl::forCustomer($foreign))->assertForbidden();
    }

    public function test_a_receipt_file_of_a_business_without_a_record_is_404(): void
    {
        Storage::fake(Receipt::DISK);
        $neighbour = $this->subscribe(Organization::factory()->create());
        $elsewhere = $this->receiptWithFile($this->layawayIn($neighbour));
        $this->withoutOrganization();

        $this->actingAs($this->account, 'account')->get(SignedFileUrl::forCustomer($elsewhere))->assertNotFound();
    }

    public function test_every_portal_query_is_scoped_by_organization(): void
    {
        $tenantTables = ['customers', 'layaways', 'installments', 'receipts', 'payments', 'payment_accounts', 'organization_settings'];
        $unscoped = [];

        DB::listen(function (QueryExecuted $query) use ($tenantTables, &$unscoped): void {
            foreach ($tenantTables as $table) {
                if (preg_match('/\b(from|join|update|into)\s+`'.$table.'`/i', $query->sql) === 1 && ! str_contains($query->sql, 'organization_id')) {
                    $unscoped[] = $query->sql;
                }
            }
        });

        $this->actingAs($this->account, 'account')->get(route('portal.dashboard'))->assertOk();
        $this->get(route('portal.layaways.show', ['code' => $this->dress->code]))->assertOk();

        $this->assertSame([], $unscoped);
    }

    public function test_logging_out_closes_both_sessions(): void
    {
        $this->actingAs($this->customerOf($this->tool), 'customer')->actingAs($this->account, 'account');

        $this->post(route('portal.logout'))->assertRedirect(route('portal.access'));

        $this->assertGuest('account');
        $this->assertGuest('customer');
        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.access'));
    }

    public function test_the_shared_session_says_the_mode_without_ids(): void
    {
        $this->actingAs($this->account, 'account')->get(route('portal.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.portal', ['mode' => 'account', 'fullName' => 'María Pérez', 'businessName' => null, 'unreadChats' => 0]));
    }

    public function test_the_access_page_offers_google_and_skips_with_a_session(): void
    {
        $this->get(route('portal.access'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('googleUrl', fn (string $url): bool => str_contains($url, '/cuenta/google/redirect')));

        $this->actingAs($this->account, 'account')->get(route('portal.access'))->assertRedirect(route('portal.dashboard'));
    }

    private function link(Layaway $layaway): void
    {
        DB::table('customers')->where('id', $layaway->customer_id)->update(['account_id' => $this->account->getKey()]);
    }

    private function customerOf(Layaway $layaway): Customer
    {
        return Customer::query()->withoutGlobalScopes()->findOrFail($layaway->customer_id);
    }

    /**
     * @return array<string, list<string>>
     */
    private function blocks(): array
    {
        $props = $this->get(route('portal.dashboard'))->viewData('page')['props'];
        $blocks = [];

        foreach ($props['businesses'] as $block) {
            $blocks[$block['business']['name']] = array_column([...$block['layaways'], ...$block['history']], 'code');
        }

        return $blocks;
    }

    private function receiptWithFile(Layaway $layaway): Receipt
    {
        $path = $layaway->organization_id.'/'.Str::uuid().'.png';
        Storage::disk(Receipt::DISK)->put($path, "\x89PNG\r\n\x1a\nla captura");

        return $this->receiptFor($layaway, ReceiptStatus::PendingReview, [
            'file_path' => $path,
            'file_mime' => 'image/png',
            'file_size' => 20,
        ]);
    }
}
