<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Settings\SettingKey;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * La pantalla de ajustes por HTTP (`REQ-ORG-006`).
 *
 * Lo que las pruebas de las acciones no ven: las dos rutas, quién entra, qué
 * llega a las props y qué pasa con una organización suspendida.
 */
final class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(\App\Domain\Tenancy\OrganizationBrand::LOGO_DISK);

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create(['slug' => 'ferreteria-el-tornillo']);

        $plan = Plan::factory()->create();

        foreach ([$neighbour, $this->organization] as $organization) {
            $this->withinOrganization($organization, function () use ($plan): void {
                Subscription::factory()->forPlan($plan)->create();

                foreach (SettingKey::cases() as $key) {
                    OrganizationSetting::query()->create([
                        'key' => $key,
                        'value' => $key->defaultValue(),
                        'updated_by' => null,
                    ]);
                }
            });
        }

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    /**
     * La pantalla trae **los diez ajustes con su definición**, la identidad y
     * la marca. El alcance de cada clave viaja con ella (RB-4): quien cambia
     * un plazo tiene que saber a qué afecta antes de guardarlo, y esa frase
     * sale del enum y no del `.tsx`.
     */
    public function test_the_page_carries_the_settings_with_their_scope(): void
    {
        $this->actingAs($this->owner())
            ->get('/backoffice/ajustes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/settings/Index')
                ->has('settings', 10)
                ->has('definitions', 10)
                ->where('settings.grace_period_days', 3)
                ->where('identity.slug', 'ferreteria-el-tornillo')
                ->where('brand.brandingEnabled', false));
    }

    /**
     * Y la definición de la ventana del inicial dice que **no mueve lo ya
     * fijado**, que es RB-4 en la pantalla.
     */
    public function test_a_future_only_key_says_so_on_the_screen(): void
    {
        $this->actingAs($this->owner())
            ->get('/backoffice/ajustes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('definitions.4.key', 'down_payment_window_hours')
                ->where('definitions.4.scope', 'future_only')
                ->where('definitions.4.scopeNote', fn (string $note): bool => str_contains($note, 'no mueve fechas ya fijadas')));
    }

    /**
     * RB-1 y SP-2 · solo el `owner`. Es `403` y no `404`: el recurso es de su
     * propia organización, así que no hay nada que ocultar.
     */
    public function test_a_member_cannot_open_or_save_settings(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->actingAs($member)->get('/backoffice/ajustes')->assertForbidden();

        $this->actingAs($member)
            ->put('/backoffice/ajustes', ['settings' => ['grace_period_days' => 7]])
            ->assertForbidden();

        $this->actingAs($member)
            ->put('/backoffice/ajustes/identidad', $this->identityPayload())
            ->assertForbidden();
    }

    public function test_the_owner_saves_the_operation_settings(): void
    {
        $this->actingAs($this->owner())
            ->put('/backoffice/ajustes', ['settings' => ['grace_period_days' => 7]])
            ->assertRedirect('/backoffice/ajustes');

        $this->assertSame(7, $this->withinOrganization(
            $this->organization,
            fn (): mixed => app(Settings::class)->get(SettingKey::GracePeriodDays),
        ));
    }

    /**
     * SP-1 · un valor fuera de rango es `422` con su campo.
     */
    public function test_a_value_out_of_range_answers_422(): void
    {
        $this->actingAs($this->owner())
            ->putJson('/backoffice/ajustes', ['settings' => ['default_installments_count' => 99]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_installments_count');
    }

    /**
     * RB-2 · una clave desconocida no rompe el guardado.
     */
    public function test_an_unknown_key_does_not_break_the_save(): void
    {
        $this->actingAs($this->owner())
            ->put('/backoffice/ajustes', ['settings' => [
                'grace_period_days' => 7,
                'storefront_enabled' => true,
            ]])
            ->assertRedirect('/backoffice/ajustes');

        $this->assertDatabaseMissing('organization_settings', [
            'organization_id' => $this->organization->getKey(),
            'key' => 'storefront_enabled',
        ]);
    }

    /**
     * La identidad se guarda por su propia ruta, con el logo incluido.
     */
    public function test_the_owner_saves_the_identity_with_its_logo(): void
    {
        $this->actingAs($this->owner())
            ->put('/backoffice/ajustes/identidad', $this->identityPayload([
                'name' => 'Ferretería renombrada',
                'phone' => '0414-1234567',
                'logo' => UploadedFile::fake()->image('logo.png'),
            ]))
            ->assertRedirect('/backoffice/ajustes');

        $this->organization->refresh();

        $this->assertSame('Ferretería renombrada', $this->organization->name);
        $this->assertSame('0414-1234567', $this->organization->phone);
        $this->assertNotNull($this->organization->logo_path);
    }

    /**
     * SP-8 · el enlace reservado se rechaza con un error **en su campo**, que
     * es donde la persona lo puede corregir.
     */
    public function test_a_reserved_slug_answers_with_a_field_error(): void
    {
        $this->actingAs($this->owner())
            ->putJson('/backoffice/ajustes/identidad', $this->identityPayload(['slug' => 'plataforma']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        $this->assertSame('ferreteria-el-tornillo', $this->organization->refresh()->slug);
    }

    /**
     * El enlace se normaliza antes de validarse: quien escribe «Mi Negocio»
     * quiere `mi-negocio`, no un error de formato.
     */
    public function test_the_slug_is_slugified_before_validating(): void
    {
        $this->actingAs($this->owner())
            ->put('/backoffice/ajustes/identidad', $this->identityPayload(['slug' => 'Mi Negocio Nuevo']))
            ->assertRedirect('/backoffice/ajustes');

        $this->assertSame('mi-negocio-nuevo', $this->organization->refresh()->slug);
    }

    /**
     * `REQ-ORG-008` RB-3 · configurar plazos no es cobrar ni aprobar
     * comprobantes, así que la pantalla **no sobrevive a la suspensión**. Como
     * en el resto del grupo de `owner`, la omisión del marcador es la
     * declaración.
     */
    public function test_settings_do_not_survive_a_suspension(): void
    {
        $owner = $this->owner();

        $this->organization->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($owner)->get('/backoffice/ajustes')->assertForbidden();

        $this->actingAs($owner)
            ->put('/backoffice/ajustes', ['settings' => ['grace_period_days' => 7]])
            ->assertForbidden();

        // Y el panel sí, que es donde se ve el aviso para regularizar.
        $this->actingAs($owner)->get('/backoffice')->assertOk();
    }

    // -------------------------------------------------------------------

    private function owner(): User
    {
        $owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());

        $this->withoutOrganization();

        return $owner;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function identityPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ferretería El Tornillo',
            'slug' => 'ferreteria-el-tornillo',
            'document' => 'J-12345678-9',
            'phone' => '',
            'timezone' => 'America/Caracas',
            'brand_color' => '',
            'storefront_enabled' => false,
        ], $overrides);
    }
}
