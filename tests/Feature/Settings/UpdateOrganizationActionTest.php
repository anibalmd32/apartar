<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Actions\Organization\UpdateOrganizationAction;
use App\Actions\Organization\UpdateOrganizationData;
use App\Domain\Catalog\PublishedCatalogs;
use App\Domain\Tenancy\OrganizationBrand;
use App\Domain\Tenancy\SlugLockedException;
use App\Domain\Tenancy\SlugTakenException;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La identidad del negocio (`REQ-ORG-006` RB-5, RB-5b, SP-2, SP-3, SP-5,
 * SP-8).
 *
 * Lo que más importa aquí es lo que **no** hace la acción: no comprueba la
 * función de plan antes de guardar el branding. Bloquear el guardado rompería
 * SP-3, y con él la promesa de que subir de plan no cuesta rellenar el
 * formulario otra vez.
 */
final class UpdateOrganizationActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(OrganizationBrand::LOGO_DISK);

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create(['slug' => 'ferreteria-el-tornillo']);

        $this->actingAsOrganization($this->organization);
    }

    /**
     * El happy path, con el teléfono que `T-014` acaba de darle columna.
     */
    public function test_the_owner_saves_the_identity(): void
    {
        $owner = $this->subscribedOwner();

        $this->action()->handle($this->data(name: 'Ferretería El Tornillo', phone: '0414-1234567'), $owner);

        $this->organization->refresh();

        $this->assertSame('Ferretería El Tornillo', $this->organization->name);
        $this->assertSame('0414-1234567', $this->organization->phone);
    }

    /**
     * SP-2 · un `member` recibe `403`.
     */
    public function test_a_member_cannot_change_the_identity(): void
    {
        $this->subscribedOwner();
        $member = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->action()->handle($this->data(), $member);
    }

    /**
     * **RB-5b y SP-3 · en Free el branding se guarda y no se aplica.**
     *
     * Es la regla que más fácil sería implementar al revés. Un
     * `assertHasFeature()` aquí daría `403` al guardar, y entonces subir de
     * plan costaría volver a configurar el logo y el color: exactamente lo que
     * SP-3 prohíbe cuando dice que al subir «se activa sin volver a
     * configurarlo».
     */
    public function test_branding_is_saved_on_a_plan_that_does_not_apply_it(): void
    {
        $owner = $this->subscribedOwner(withBranding: false);

        $this->action()->handle($this->data(brandColor: '#1F6FEB'), $owner);

        $this->organization->refresh();

        $this->assertSame('#1f6feb', $this->organization->brand_color, 'El color no se guardó en un plan sin branding.');

        $brand = $this->action()->brandOf($this->organization);

        $this->assertFalse($brand->brandingEnabled, 'El plan Free no debería aplicar el branding.');
        $this->assertSame('#1f6feb', $brand->brandColor, 'El color guardado tiene que viajar igualmente a la pantalla.');
    }

    /**
     * Y la otra mitad de SP-3: **al subir de plan se activa solo**, sin tocar
     * el formulario. Se guarda en Free, se cambia el plan y ya se aplica.
     */
    public function test_branding_activates_on_its_own_when_the_plan_improves(): void
    {
        $owner = $this->subscribedOwner(withBranding: false);

        $this->action()->handle($this->data(brandColor: '#1f6feb'), $owner);

        $this->assertFalse($this->action()->brandOf($this->organization->refresh())->brandingEnabled);

        $pro = Plan::factory()->withEveryFeature()->create();

        Subscription::query()->firstOrFail()->forceFill(['plan_id' => $pro->getKey()])->save();

        $brand = $this->action()->brandOf($this->organization->refresh());

        $this->assertTrue($brand->brandingEnabled);
        $this->assertSame('#1f6feb', $brand->brandColor, 'Subir de plan no debería exigir reconfigurar nada.');
    }

    /**
     * El color se normaliza: `#FFF` y `#ffffff` son el mismo color, y
     * guardarlo de dos formas convertiría cualquier comparación futura en una
     * respuesta falsa.
     */
    public function test_the_brand_color_is_normalised(): void
    {
        $owner = $this->subscribedOwner();

        $this->action()->handle($this->data(brandColor: '#ABC'), $owner);

        $this->assertSame('#aabbcc', $this->organization->refresh()->brand_color);
    }

    /**
     * El logo se guarda en el disco público y la ruta queda en la fila.
     */
    public function test_the_logo_is_stored_and_exposed_as_a_url(): void
    {
        $owner = $this->subscribedOwner();

        $this->action()->handle($this->data(logo: UploadedFile::fake()->image('logo.png')), $owner);

        $this->organization->refresh();

        $this->assertNotNull($this->organization->logo_path);
        Storage::disk(OrganizationBrand::LOGO_DISK)->assertExists($this->organization->logo_path);

        $this->assertNotNull($this->action()->brandOf($this->organization)->logoUrl);
    }

    /**
     * Sustituir el logo borra el anterior: nadie más lo referencia, y el
     * almacenamiento es una métrica de plan (`REQ-SUB-003`).
     */
    public function test_replacing_the_logo_removes_the_previous_one(): void
    {
        $owner = $this->subscribedOwner();

        $this->action()->handle($this->data(logo: UploadedFile::fake()->image('viejo.png')), $owner);
        $first = $this->organization->refresh()->logo_path;

        $this->action()->handle($this->data(logo: UploadedFile::fake()->image('nuevo.png')), $owner);
        $second = $this->organization->refresh()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk(OrganizationBrand::LOGO_DISK)->assertMissing((string) $first);
        Storage::disk(OrganizationBrand::LOGO_DISK)->assertExists((string) $second);
    }

    /**
     * Guardar sin logo **no borra el que hubiera**: cambiar el teléfono no
     * puede costarle a nadie su marca.
     */
    public function test_saving_without_a_logo_keeps_the_current_one(): void
    {
        $owner = $this->subscribedOwner();

        $this->action()->handle($this->data(logo: UploadedFile::fake()->image('logo.png')), $owner);
        $path = $this->organization->refresh()->logo_path;

        $this->action()->handle($this->data(phone: '0212-5550000'), $owner);

        $this->assertSame($path, $this->organization->refresh()->logo_path);
    }

    /**
     * SP-6 y RB-7 · **cambiar la zona horaria no mueve fechas ya fijadas.**
     *
     * La zona rige la **presentación** y el horario de los envíos futuros. Lo
     * que se puede comprobar hoy es lo que sostiene esa regla: el cambio
     * escribe la columna de la organización y **no toca ninguna otra fila**.
     * Los apartados con sus `expires_at` llegan con `T-045`; lo que no puede
     * pasar nunca es que guardar la zona sea una operación que reescribe
     * fechas, y eso se ve aquí.
     */
    public function test_changing_the_timezone_moves_no_stored_date(): void
    {
        $owner = $this->subscribedOwner();

        $createdAt = $this->organization->created_at;
        $subscriptionStart = Subscription::query()->firstOrFail()->started_at;

        $this->action()->handle(new UpdateOrganizationData(
            name: $this->organization->name,
            slug: $this->organization->slug,
            document: null,
            phone: null,
            timezone: 'America/Bogota',
            brandColor: null,
            storefrontEnabled: false,
        ), $owner);

        $this->organization->refresh();

        $this->assertSame('America/Bogota', $this->organization->timezone);
        $this->assertEquals($createdAt, $this->organization->created_at, 'Cambiar la zona movió una fecha ya fijada.');
        $this->assertEquals($subscriptionStart, Subscription::query()->firstOrFail()->started_at);
    }

    /**
     * SP-5 · **con catálogos publicados el enlace no se cambia.**
     *
     * `catalogs` llega con `T-030`, así que la comprobación se ejercita
     * sustituyendo `PublishedCatalogs` en el contenedor. Es lo que permite que
     * la regla esté escrita con su forma definitiva —y probada— antes de que
     * exista el modelo: el día que `T-030` rellene el cuerpo de `exist()`, ni
     * la acción ni este test cambian.
     */
    public function test_the_slug_is_locked_once_there_are_published_catalogs(): void
    {
        $owner = $this->subscribedOwner();

        $this->withPublishedCatalogs();

        try {
            $this->action()->handle($this->data(slug: 'otro-nombre'), $owner);
            $this->fail('Cambiar el enlace con catálogos publicados debió fallar.');
        } catch (SlugLockedException $exception) {
            $this->assertStringContainsString('ferreteria-el-tornillo', $exception->getMessage());
            $this->assertSame(422, SlugLockedException::STATUS);
        }

        $this->assertSame('ferreteria-el-tornillo', $this->organization->refresh()->slug);
    }

    /**
     * Y lo demás **sí** se puede seguir editando: un negocio con catálogos
     * publicados cambia su teléfono o su color sin que el enlace le bloquee el
     * formulario entero.
     */
    public function test_a_locked_slug_does_not_block_the_rest_of_the_form(): void
    {
        $owner = $this->subscribedOwner();

        $this->withPublishedCatalogs();

        $this->action()->handle($this->data(phone: '0212-5550000'), $owner);

        $this->assertSame('0212-5550000', $this->organization->refresh()->phone);
    }

    /**
     * SP-8 · el enlace reservado se rechaza al renombrar igual que al
     * registrar. `ReservedSlugs` existe desde `T-010` y se reutiliza: una
     * segunda lista se desincronizaría en la dirección peligrosa, la del
     * formulario que todavía la deja pasar.
     */
    public function test_a_reserved_slug_is_rejected(): void
    {
        $owner = $this->subscribedOwner();

        $this->expectException(SlugTakenException::class);

        $this->action()->handle($this->data(slug: 'backoffice'), $owner);
    }

    /**
     * Y el de otra organización, también: el `slug` es único en toda la
     * plataforma.
     */
    public function test_a_slug_taken_by_another_organization_is_rejected(): void
    {
        $owner = $this->subscribedOwner();

        $this->expectException(SlugTakenException::class);

        $this->action()->handle($this->data(slug: $this->neighbourOrganization()->slug), $owner);
    }

    /**
     * Guardar sin cambiar el enlace no lo comprueba contra sí mismo: el índice
     * único lo encontraría ocupado por su propio dueño.
     */
    public function test_keeping_the_same_slug_is_not_a_collision(): void
    {
        $owner = $this->subscribedOwner();

        $this->action()->handle($this->data(name: 'Otro nombre'), $owner);

        $this->assertSame('ferreteria-el-tornillo', $this->organization->refresh()->slug);
        $this->assertSame('Otro nombre', $this->organization->name);
    }

    /**
     * RB-6 · la vitrina es un interruptor de identidad y se gobierna aquí. La
     * página que enciende llega con `T-036`.
     */
    public function test_the_storefront_switch_is_part_of_the_identity(): void
    {
        $owner = $this->subscribedOwner();

        $this->assertFalse($this->organization->storefront_enabled);

        $this->action()->handle($this->data(storefrontEnabled: true), $owner);

        $this->assertTrue($this->organization->refresh()->storefront_enabled);
    }

    // -------------------------------------------------------------------

    private function action(): UpdateOrganizationAction
    {
        return app(UpdateOrganizationAction::class);
    }

    /**
     * Sustituye la consulta de catálogos publicados por una que dice que sí.
     */
    private function withPublishedCatalogs(): void
    {
        $this->app->bind(PublishedCatalogs::class, fn (): PublishedCatalogs => new class extends PublishedCatalogs
        {
            public function exist(): bool
            {
                return true;
            }
        });
    }

    private function data(
        string $name = 'Ferretería El Tornillo',
        ?string $slug = null,
        ?string $phone = null,
        ?string $brandColor = null,
        bool $storefrontEnabled = false,
        ?UploadedFile $logo = null,
    ): UpdateOrganizationData {
        return new UpdateOrganizationData(
            name: $name,
            slug: $slug ?? $this->organization->slug,
            document: 'J-12345678-9',
            phone: $phone,
            timezone: 'America/Caracas',
            brandColor: $brandColor,
            storefrontEnabled: $storefrontEnabled,
            logo: $logo,
        );
    }

    private function subscribedOwner(bool $withBranding = true): User
    {
        $plan = $withBranding
            ? Plan::factory()->withEveryFeature()->create()
            : Plan::factory()->create();

        Subscription::factory()->forPlan($plan)->create();

        return User::factory()->owner()->create();
    }
}
