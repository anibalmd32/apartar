<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\FakesPwnedPasswords;
use Tests\TestCase;

/**
 * El registro por HTTP (`REQ-ORG-001`).
 *
 * Es la puerta de entrada al producto, así que lo que se comprueba aquí no es
 * solo que cree filas: es qué recibe quien se equivoca. Un `422` sin
 * explicación en la primera pantalla es un registro abandonado.
 */
final class RegisterOrganizationTest extends TestCase
{
    use FakesPwnedPasswords, RefreshDatabase;

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

    /**
     * Quien ya entró no se registra: `users.email` es único en toda la
     * plataforma, así que no podría abrir un segundo negocio con su correo.
     */
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

    /**
     * SP-2 · un correo ya usado como cuenta de equipo se rechaza, y el mensaje
     * dice qué hacer: iniciar sesión o usar otro.
     */
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

    /**
     * SP-3 · y un correo que ya existe como **cuenta de cliente final** se
     * acepta. No es un descuido: son dos identidades distintas (`D-8`). La
     * misma persona puede ser dueña de un negocio en Apartar y clienta de
     * otro, y su correo de Google no tiene por qué ser otro.
     */
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

    /**
     * SP-1 · el enlace ocupado vuelve **con la variante libre dentro del
     * mensaje**, que es lo único accionable que se le puede decir a quien
     * choca con el nombre de un negocio que no conoce.
     */
    public function test_a_taken_slug_answers_with_a_free_variant(): void
    {
        Organization::factory()->create(['slug' => 'ferreteria-el-tornillo']);

        $this->post('/registro', self::VALID)->assertSessionHasErrors('organization_slug');

        $errors = session('errors');
        $this->assertNotNull($errors);
        $this->assertStringContainsString('ferreteria-el-tornillo-2', $errors->first('organization_slug'));
    }

    /**
     * Un enlace reservado se corrige junto al campo: la vitrina vive en
     * `/{slug}` y `/precios` es una ruta de la plataforma, así que ese negocio
     * vería su web mostrando otra cosa.
     */
    public function test_a_reserved_slug_is_refused(): void
    {
        $this->post('/registro', [...self::VALID, 'organization_slug' => 'precios'])
            ->assertSessionHasErrors('organization_slug');

        $this->assertSame(0, Organization::query()->count());
    }

    /**
     * SP-7 · un nombre que no produce enlace exige escribirlo a mano, en vez
     * de inventarle al negocio una URL que no sabría explicar.
     */
    public function test_a_name_without_a_usable_slug_asks_for_one(): void
    {
        $this->post('/registro', [...self::VALID, 'organization_name' => '🎉🎉🎉', 'organization_slug' => ''])
            ->assertSessionHasErrors('organization_slug');

        $this->assertSame(0, Organization::query()->count());
    }

    /**
     * Y con el nombre de emojis pero un enlace escrito a mano, el registro
     * sigue adelante: lo que faltaba era la URL, no el nombre.
     */
    public function test_the_same_name_works_with_a_hand_written_slug(): void
    {
        $this->post('/registro', [...self::VALID, 'organization_name' => '🎉🎉🎉', 'organization_slug' => 'fiesta'])
            ->assertRedirect(route('dashboard'));

        $this->assertSame('fiesta', Organization::query()->sole()->slug);
    }

    /**
     * RB-4 y SP-4 · la contraseña cumple `Password::min(12)->mixedCase()
     * ->numbers()->uncompromised()`. Lo último es lo que de verdad protege una
     * cuenta que gobierna la cobranza de un negocio: larga y filtrada no es
     * fuerte.
     */
    public function test_a_weak_password_is_refused(): void
    {
        $this->post('/registro', [
            ...self::VALID,
            'owner_password' => 'corta1A',
            'owner_password_confirmation' => 'corta1A',
        ])->assertSessionHasErrors('owner_password');

        $this->assertSame(0, Organization::query()->count());
    }

    /**
     * `Password123456` cumple longitud, mayúsculas y números, así que es la
     * regla de filtraciones —y solo ella— la que la rechaza.
     *
     * **El servicio se falsea** (`REQ-QAX-003` RB-9): hasta `T-003b` este caso
     * salía a `api.pwnedpasswords.com` de verdad en cada ejecución, y lo que
     * lo destapó fue poner la suite en paralelo. La regla se sigue ejercitando
     * entera —calcula el hash, lo parte y busca su sufijo—; lo único que
     * cambia es quién sirve la respuesta, y `assertPwnedServiceWasAsked()`
     * comprueba que se le llegó a preguntar.
     */
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

    /**
     * RB-8 y SP-6 · cinco por hora y por IP; el **sexto** recibe `429`.
     *
     * Entre registro y registro se limpia la sesión porque cada uno es un
     * visitante distinto desde la misma IP, que es lo que el límite acota. Sin
     * eso, el segundo intento lo pararía `guest` —la sesión quedó autenticada
     * al registrarse— y el test mediría otra cosa.
     */
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
