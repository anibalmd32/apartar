<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domain\Customer\AccessVerificationFailedException;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Http\Requests\Portal\StartScopedSessionRequest;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ScopedAccessTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private Layaway $layaway;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create(['name' => 'Ferretería Oriente']));
        $this->layaway = $this->layawayIn($this->organization);
        DB::table('customers')->where('id', $this->layaway->customer_id)->update(['document_number' => 'V12345678', 'full_name' => 'María González']);
        $this->customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->findOrFail($this->layaway->customer_id));
        $this->withoutOrganization();
    }

    public function test_document_and_code_open_a_scoped_session(): void
    {
        $code = strtolower(str_replace('-', '', $this->layaway->code));

        $response = $this->enter('V-12.345.678', $code);

        $response->assertRedirect(route('portal.dashboard'));
        $response->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->customer, 'customer');
        $this->assertGuest('web');
        $this->assertNotNull(DB::table('customers')->where('id', $this->customer->getKey())->value('last_login_at'));
        $this->assertNotNull(DB::table('customers')->where('id', $this->customer->getKey())->value('remember_token'), 'la sesión se recuerda.');

        $this->get(route('portal.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('portal/Dashboard')
                ->where('businesses.0.business.name', 'Ferretería Oriente')
                ->where('session.displayName', 'María González')
                ->where('businesses.0.layaways.0.code', $this->layaway->code)
                ->where('businesses.0.layaways.0.statusLabel', 'Esperando la inicial')
                ->where('auth.portal', ['mode' => 'scoped', 'fullName' => 'María González', 'businessName' => 'Ferretería Oriente', 'unreadChats' => 0])
                ->where('totals', null)
                ->missing('businesses.0.layaways.0.id'));
    }

    public function test_the_code_of_another_person_fails(): void
    {
        $other = $this->layawayIn($this->organization);
        DB::table('customers')->where('id', $other->customer_id)->update(['document_number' => 'V87654321']);

        $this->enter('V-87.654.321', $this->layaway->code)
            ->assertSessionHasErrors(['code' => AccessVerificationFailedException::MESSAGE]);

        $this->assertGuest('customer');
    }

    public function test_the_three_failures_are_indistinguishable(): void
    {
        $other = $this->layawayIn($this->organization);
        DB::table('customers')->where('id', $other->customer_id)->update(['document_number' => 'V87654321']);

        $responses = [
            'código inexistente' => $this->enter('V-12.345.678', 'AP-ZZZZZZZZ'),
            'código de otra persona' => $this->enter('V-12.345.678', $other->code),
            'documento que no es de nadie' => $this->enter('V-11.111.111', $this->layaway->code),
        ];

        foreach ($responses as $mode => $response) {
            $response->assertStatus(302);
            $this->assertSame(url('/'), $response->headers->get('Location'), "«{$mode}» redirige a otro sitio.");
            $errors = session('errors');
            $this->assertInstanceOf(ViewErrorBag::class, $errors);
            $this->assertSame([AccessVerificationFailedException::MESSAGE], $errors->get('code'), "«{$mode}» dice otra cosa.");
            $this->assertSame([], $errors->get('document'), "«{$mode}» señala la cédula.");
        }

        $this->assertGuest('customer');
    }

    public function test_the_sixth_attempt_is_rate_limited(): void
    {
        foreach (range(1, StartScopedSessionRequest::MAX_ATTEMPTS) as $attempt) {
            $this->enter('V-12.345.678', 'AP-ZZZZZZZZ')->assertSessionHasErrors('code');
        }

        $response = $this->enter('V-12.345.678', $this->layaway->code);

        $response->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertGuest('customer');

        $this->enter('V-99.999.999', 'AP-ZZZZZZZZ')->assertStatus(302);
    }

    public function test_a_malformed_document_is_rejected_by_its_shape_without_consuming_an_attempt(): void
    {
        foreach (range(1, StartScopedSessionRequest::MAX_ATTEMPTS + 1) as $attempt) {
            $this->enter('hola', $this->layaway->code)->assertSessionHasErrors('document');
        }

        $this->enter('V-12.345.678', $this->layaway->code)->assertRedirect(route('portal.dashboard'));
    }

    public function test_it_gives_access_to_every_layaway_of_the_customer(): void
    {
        $second = $this->layawayIn($this->organization, LayawayStatus::Active);
        DB::table('layaways')->where('id', $second->getKey())->update(['customer_id' => $this->customer->getKey()]);
        $foreign = $this->layawayIn($this->organization);

        $this->enter('V-12.345.678', $this->layaway->code);

        $codes = $this->dashboardCodes();
        sort($codes);
        $expected = [$this->layaway->code, $second->code];
        sort($expected);

        $this->assertSame($expected, $codes);
        $this->assertNotContains($foreign->code, $codes);
    }

    public function test_it_works_with_terminal_layaways_and_a_suspended_organization(): void
    {
        $this->withinOrganization($this->organization, fn () => app(LayawayStateMachine::class)->transition($this->layaway, LayawayStatus::Expired));
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $this->withoutOrganization();

        $this->enter('V-12.345.678', $this->layaway->code)->assertRedirect(route('portal.dashboard'));

        $this->get(route('portal.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('businesses.0.history.0.statusLabel', 'Vencido sin inicial'));
    }

    public function test_without_a_session_the_portal_sends_to_the_access_page_and_back(): void
    {
        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.access'));

        $this->enter('V-12.345.678', $this->layaway->code)->assertRedirect(route('portal.dashboard'));
    }

    public function test_the_access_page_renders_and_redirects_a_customer_already_inside(): void
    {
        $this->get(route('portal.access'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('portal/Access'));

        $this->enter('V-12.345.678', $this->layaway->code);

        $this->get(route('portal.access'))->assertRedirect(route('portal.dashboard'));
    }

    public function test_logging_out_ends_the_session(): void
    {
        $this->enter('V-12.345.678', $this->layaway->code);
        $token = session()->token();

        $this->post(route('portal.logout'))->assertRedirect(route('portal.access'));

        $this->assertGuest('customer');
        $this->assertNotSame($token, session()->token());
        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.access'));
    }

    public function test_the_team_and_the_customer_sessions_grant_nothing_to_each_other(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();

        $this->actingAs($member, 'web')->get(route('portal.dashboard'))->assertRedirect(route('portal.access'));

        auth()->guard('web')->logout();
        $this->app->make('auth')->forgetGuards();

        $this->enter('V-12.345.678', $this->layaway->code);
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function enter(string $document, string $code): TestResponse
    {
        return $this->from('/')->post(route('portal.access.code'), ['document' => $document, 'code' => $code]);
    }

    /**
     * @return list<string>
     */
    private function dashboardCodes(): array
    {
        $codes = [];

        $this->get(route('portal.dashboard'))->assertInertia(function (AssertableInertia $page) use (&$codes): void {
            /** @var array{layaways: list<array{code: string}>, history: list<array{code: string}>} $block */
            $block = $page->toArray()['props']['businesses'][0];
            $codes = array_map(fn (array $layaway): string => $layaway['code'], [...$block['layaways'], ...$block['history']]);
        });

        return $codes;
    }
}
