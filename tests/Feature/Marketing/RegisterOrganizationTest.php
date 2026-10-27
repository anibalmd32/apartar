<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\FakesPwnedPasswords;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class RegisterOrganizationTest extends TestCase
{
    use FakesPwnedPasswords, ReusesMigratedDatabase;

    /**
     * @var array<string, string>
     */
    private const VALID = [
        'organization_name' => 'Ferretería El Tornillo',
        'organization_slug' => 'ferreteria-el-tornillo',
        'owner_name' => 'Ana Pérez',
        'owner_email' => 'ana@ejemplo.test',
        'owner_password' => 'ContraseñaLarga123',
        'owner_password_confirmation' => 'ContraseñaLarga123',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_the_form_renders_its_own_page(): void
    {
        $this->get('/registro')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('marketing/Register'));
    }

    public function test_an_authenticated_visitor_is_sent_to_the_backoffice(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $user = $this->withinOrganization($organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();

        $this->actingAs($user)->get('/registro')->assertRedirect(route('dashboard'));
    }

    public function test_a_valid_registration_lands_in_the_backoffice_signed_in(): void
    {
        $response = $this->post('/registro', self::VALID);

        $response->assertRedirect(route('dashboard'));

        $organization = Organization::query()->sole();
        $owner = $this->withinOrganization($organization, fn (): User => User::query()->sole());

        $this->assertAuthenticatedAs($owner);
        $this->assertTrue(Hash::check('ContraseñaLarga123', (string) $owner->password));
    }

    public function test_an_email_already_used_by_a_team_member_is_refused(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $this->withinOrganization(
            $organization,
            fn (): User => User::factory()->create(['email' => 'ana@ejemplo.test']),
        );
        $this->withoutOrganization();

        $this->post('/registro', self::VALID)
            ->assertSessionHasErrors('owner_email');

        $this->assertSame(2, Organization::query()->count());
        $this->assertGuest();
    }

    public function test_an_email_that_exists_as_a_customer_account_is_accepted(): void
    {
        DB::table('accounts')->insert([
            'display_name' => 'Ana Pérez',
            'email' => 'ana@ejemplo.test',
            'locale' => 'es',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post('/registro', self::VALID)->assertRedirect(route('dashboard'));

        $this->assertSame(1, Organization::query()->count());
        $this->assertAuthenticated();
    }

    public function test_a_taken_slug_answers_with_a_free_variant(): void
    {
        Organization::factory()->create(['slug' => 'ferreteria-el-tornillo']);

        $this->post('/registro', self::VALID)->assertSessionHasErrors('organization_slug');

        $errors = session('errors');
        $this->assertNotNull($errors);
        $this->assertStringContainsString('ferreteria-el-tornillo-2', $errors->first('organization_slug'));
    }

    public function test_the_slug_check_says_whether_it_is_free_and_which_one_is(): void
    {
        Organization::factory()->create(['slug' => 'ferreteria-el-tornillo']);

        $this->getJson(route('register.slug', ['slug' => 'Ferretería El Tornillo']))
            ->assertOk()
            ->assertExactJson(['slug' => 'ferreteria-el-tornillo', 'available' => false, 'suggestion' => 'ferreteria-el-tornillo-2']);
        $this->getJson(route('register.slug', ['slug' => 'precios']))->assertJsonPath('available', false);
        $this->getJson(route('register.slug', ['slug' => 'mi-tienda']))->assertJsonPath('available', true)->assertJsonPath('suggestion', null);

        $route = app('router')->getRoutes()->getByName('register.slug');
        $this->assertContains('throttle:registro-enlace', $route?->gatherMiddleware() ?? []);
    }

    public function test_a_reserved_slug_is_refused(): void
    {
        $this->post('/registro', [...self::VALID, 'organization_slug' => 'precios'])
            ->assertSessionHasErrors('organization_slug');

        $this->assertSame(0, Organization::query()->count());
    }

    public function test_a_name_without_a_usable_slug_asks_for_one(): void
    {
        $this->post('/registro', [...self::VALID, 'organization_name' => '🎉🎉🎉', 'organization_slug' => ''])
            ->assertSessionHasErrors('organization_slug');

        $this->assertSame(0, Organization::query()->count());
    }

    public function test_the_same_name_works_with_a_hand_written_slug(): void
    {
        $this->post('/registro', [...self::VALID, 'organization_name' => '🎉🎉🎉', 'organization_slug' => 'fiesta'])
            ->assertRedirect(route('dashboard'));

        $this->assertSame('fiesta', Organization::query()->sole()->slug);
    }

    public function test_a_weak_password_is_refused(): void
    {
        foreach (['casa1', 'solotexto', '12345678'] as $weak) {
            $this->post('/registro', [
                ...self::VALID,
                'owner_password' => $weak,
                'owner_password_confirmation' => $weak,
            ])->assertSessionHasErrors('owner_password');
        }

        $this->assertSame(0, Organization::query()->count());
    }

    public function test_six_characters_with_a_letter_and_a_number_are_enough(): void
    {
        $this->post('/registro', [
            ...self::VALID,
            'owner_password' => 'casa24',
            'owner_password_confirmation' => 'casa24',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Organization::query()->count());
    }

    public function test_a_compromised_password_is_refused(): void
    {
        $this->fakePwnedPassword('Password123456');

        $this->post('/registro', [
            ...self::VALID,
            'owner_password' => 'Password123456',
            'owner_password_confirmation' => 'Password123456',
        ])->assertSessionHasErrors('owner_password');

        $this->assertPwnedServiceWasAsked('Password123456');
        $this->assertSame(0, Organization::query()->count());
    }

    public function test_a_mistyped_confirmation_is_refused(): void
    {
        $this->post('/registro', [...self::VALID, 'owner_password_confirmation' => 'OtraCosaLarga123'])
            ->assertSessionHasErrors('owner_password');
    }

    public function test_the_sixth_registration_from_one_ip_is_throttled(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->registerAsNewVisitor($attempt)->assertRedirect(route('dashboard'));
        }

        $this->registerAsNewVisitor(6)->assertStatus(429);

        $this->assertSame(5, Organization::query()->count());
    }

    public function test_it_requires_the_fields_that_have_no_default(): void
    {
        $this->post('/registro', [])->assertSessionHasErrors([
            'organization_name',
            'owner_name',
            'owner_email',
            'owner_password',
        ]);
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function registerAsNewVisitor(int $number): \Illuminate\Testing\TestResponse
    {
        $this->flushSession();
        $this->app->make('auth')->forgetGuards();

        return $this->post('/registro', [
            ...self::VALID,
            'organization_name' => 'Negocio '.$number,
            'organization_slug' => 'negocio-'.$number,
            'owner_email' => 'ana'.$number.'@ejemplo.test',
        ]);
    }
}
