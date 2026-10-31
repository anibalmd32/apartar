<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domain\Layaway\LayawayStatus;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PayTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private Layaway $older;

    private Layaway $newer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create(['name' => 'Ferretería Oriente']));
        $this->older = $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->newer = $this->layawayIn($this->organization);
        $completed = $this->layawayIn($this->organization, LayawayStatus::Completed);

        $customerId = $this->older->customer_id;
        DB::table('customers')->where('id', $customerId)->update(['document_number' => 'V12345678', 'full_name' => 'María González']);
        DB::table('layaways')->whereIn('id', [$this->newer->getKey(), $completed->getKey()])->update(['customer_id' => $customerId]);
        DB::table('layaways')->where('id', $this->older->getKey())->update(['created_at' => CarbonImmutable::now()->subDays(10)]);
        DB::table('layaways')->where('id', $this->newer->getKey())->update(['created_at' => CarbonImmutable::now()->subMinute()]);
        $this->withoutOrganization();
    }

    public function test_the_access_page_arrives_with_the_code_filled_and_promises_the_payment(): void
    {
        $this->get(route('portal.access', ['codigo' => strtolower($this->newer->code)]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('portal/Access')
                ->where('code', $this->newer->code)
                ->where('googleUrl', route('account.google.redirect', ['luego' => route('portal.pay', absolute: false)])));
    }

    public function test_a_malformed_code_is_not_filled(): void
    {
        $this->get(route('portal.access', ['codigo' => '<script>']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('code', null)
                ->where('googleUrl', route('account.google.redirect', ['luego' => route('portal.dashboard', absolute: false)])));
    }

    public function test_after_the_document_it_lands_on_the_list_to_pay_the_newest_first(): void
    {
        $this->get(route('portal.access', ['codigo' => $this->newer->code]));

        $this->enter('V-12.345.678', $this->newer->code)->assertRedirect(route('portal.pay'));

        $this->get(route('portal.pay'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/Pay')
            ->has('layaways', 2)
            ->where('layaways.0.code', $this->newer->code)
            ->where('layaways.0.businessName', 'Ferretería Oriente')
            ->where('layaways.0.payUrl', route('portal.receipts.create', ['code' => $this->newer->code]))
            ->where('layaways.1.code', $this->older->code)
            ->missing('layaways.0.id'));
    }

    public function test_without_a_code_the_access_still_lands_on_the_dashboard(): void
    {
        $this->get(route('portal.access'));

        $this->enter('V-12.345.678', $this->newer->code)->assertRedirect(route('portal.dashboard'));
    }

    public function test_someone_already_inside_goes_straight_to_pay(): void
    {
        $this->enter('V-12.345.678', $this->newer->code);

        $this->get(route('portal.access', ['codigo' => $this->newer->code]))->assertRedirect(route('portal.pay'));
        $this->get(route('portal.access'))->assertRedirect(route('portal.dashboard'));
    }

    public function test_the_list_needs_a_session(): void
    {
        $this->get(route('portal.pay'))->assertRedirect(route('portal.access'));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function enter(string $document, string $code): TestResponse
    {
        return $this->post(route('portal.access.code'), ['document' => $document, 'code' => $code]);
    }
}
