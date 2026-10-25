<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Actions\Customer\MergeCustomersAction;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class CustomerMergePageTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();
    }

    public function test_candidates_say_what_would_move(): void
    {
        $survivor = $this->customer('V11111111', 'María González');
        $absorbed = $this->customer('V22222222', 'María Gonzales');
        $this->layawayOf($absorbed);

        $this->actingAs($this->member)->getJson(route('customers.merge.candidates', [$survivor, 'q' => 'maría']))
            ->assertOk()
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.id', $absorbed->getKey())
            ->assertJsonPath('candidates.0.moves.layaways', 1)
            ->assertJsonPath('candidates.0.blocked', false);
    }

    public function test_merging_needs_the_absorbed_document_as_confirmation(): void
    {
        $survivor = $this->customer('V11111111', 'María González');
        $absorbed = $this->customer('V22222222', 'María Gonzales');
        $this->layawayOf($absorbed);
        $this->actingAs($this->member)->from(route('customers.show', $survivor));

        $this->post(route('customers.merge', $survivor), ['absorbed' => $absorbed->getKey(), 'confirmation' => 'V11111111'])
            ->assertSessionHasErrors(['merge' => 'Para confirmar, escribe el documento de la ficha que se va a absorber.']);
        $this->assertNotNull(DB::table('customers')->where('id', $absorbed->getKey())->first());

        $this->post(route('customers.merge', $survivor), ['absorbed' => $absorbed->getKey(), 'confirmation' => '22.222.222'])
            ->assertRedirect(route('customers.show', $survivor))
            ->assertSessionHas('status', 'Fichas fusionadas: 1 apartado y 0 comprobantes pasaron a esta ficha.');
        $this->assertNull(DB::table('customers')->where('id', $absorbed->getKey())->first());
    }

    public function test_a_neighbours_record_cannot_be_merged(): void
    {
        $ours = $this->customer('V11111111', 'María González');
        $theirs = $this->withinOrganization($this->neighbourOrganization(), fn (): Customer => Customer::factory()->create(['document_number' => 'V22222222']));
        $this->withoutOrganization();
        $this->actingAs($this->member)->from(route('customers.show', $ours));

        $this->post(route('customers.merge', $ours), ['absorbed' => $theirs->getKey(), 'confirmation' => 'V22222222'])->assertSessionHasErrors('merge');
        $this->post(route('customers.merge', $theirs), ['absorbed' => $ours->getKey(), 'confirmation' => 'V11111111'])->assertNotFound();
        $this->getJson(route('customers.merge.candidates', [$theirs, 'q' => 'maría']))->assertNotFound();
        $this->assertSame(2, DB::table('customers')->count());
    }

    public function test_merging_waits_while_the_organization_is_suspended(): void
    {
        $survivor = $this->customer('V11111111', 'María González');
        $absorbed = $this->customer('V22222222', 'María Gonzales');
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->actingAs($this->member)->get(route('customers.show', $survivor))->assertOk();
        $this->post(route('customers.merge', $survivor), ['absorbed' => $absorbed->getKey(), 'confirmation' => 'V22222222'])->assertForbidden();
        $this->assertNotNull(DB::table('customers')->where('id', $absorbed->getKey())->first());
    }

    public function test_the_absorbed_document_still_opens_the_portal_with_the_layaway_code(): void
    {
        $survivor = $this->customer('V11111111', 'María González');
        $absorbed = $this->customer('V22222222', 'María Gonzales');
        $layaway = $this->layawayOf($absorbed);
        $this->merge($survivor, $absorbed);

        $this->from('/')->post(route('portal.access.code'), ['document' => 'V-22.222.222', 'code' => $layaway->code])
            ->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticatedAs($survivor->fresh(), 'customer');
    }

    public function test_an_open_portal_session_of_the_absorbed_record_moves_to_the_survivor(): void
    {
        $survivor = $this->customer('V11111111', 'María González');
        $absorbed = $this->customer('V22222222', 'María Gonzales');
        $this->layawayOf($absorbed);
        $this->actingAs($absorbed, 'customer')->get(route('portal.dashboard'))->assertOk();

        $this->merge($survivor, $absorbed);
        $this->app['auth']->forgetGuards();
        $before = session()->getId();

        $this->get(route('portal.dashboard'))->assertOk();
        $this->assertSame($survivor->getKey(), auth('customer')->id());
        $this->assertSame($survivor->getKey(), (int) session($this->portalGuard()->getName()));
        $this->assertNotSame($before, session()->getId());
    }

    public function test_the_remember_cookie_of_the_absorbed_record_reaches_the_survivor(): void
    {
        $survivor = $this->customer('V11111111', 'María González');
        $absorbed = $this->customer('V22222222', 'María Gonzales');
        $this->layawayOf($absorbed);
        auth('customer')->login($absorbed, remember: true);
        $recaller = $this->portalGuard()->getRecallerName();
        $cookie = (string) Cookie::queued($recaller)?->getValue();
        [$id, $token, $hash] = explode('|', $cookie);
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->merge($survivor, $absorbed);

        $this->withCookie($recaller, "{$id}|otro-token|{$hash}")->get(route('portal.dashboard'))->assertRedirect(route('portal.access'));
        $this->assertGuest('customer');

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withCookie($recaller, "{$id}|{$token}|{$hash}")->get(route('portal.dashboard'))->assertOk();
        $this->assertSame($survivor->getKey(), auth('customer')->id());
    }

    public function test_authorization_comes_before_the_confirmation(): void
    {
        $survivor = $this->customer('V11111111', 'María González');
        $absorbed = $this->customer('V22222222', 'María Gonzales');
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'merge' ? false : null);

        $this->actingAs($this->member)->post(route('customers.merge', $survivor), ['absorbed' => $absorbed->getKey(), 'confirmation' => 'V99999999'])->assertForbidden();
        $this->assertNotNull(DB::table('customers')->where('id', $absorbed->getKey())->first());
    }

    private function customer(string $document, string $name): Customer
    {
        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::factory()->create(['document_number' => $document, 'full_name' => $name]));
        $this->withoutOrganization();

        return $customer;
    }

    private function layawayOf(Customer $customer): Layaway
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $orphan = $layaway->customer_id;
        DB::table('layaways')->where('id', $layaway->getKey())->update(['customer_id' => $customer->getKey()]);
        DB::table('customers')->where('id', $orphan)->delete();
        $this->withoutOrganization();

        return $layaway;
    }

    private function portalGuard(): SessionGuard
    {
        $guard = auth('customer');
        $this->assertInstanceOf(SessionGuard::class, $guard);

        return $guard;
    }

    private function merge(Customer $survivor, Customer $absorbed): void
    {
        $this->withinOrganization($this->organization, fn () => app(MergeCustomersAction::class)->handle($survivor, $absorbed, $this->member));
        $this->withoutOrganization();
    }
}
