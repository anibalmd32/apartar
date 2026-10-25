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

    public function test_the_owner_saves_the_identity(): void
    {
        $owner = $this->subscribedOwner();

        $this->action()->handle($this->data(name: 'Ferretería El Tornillo', phone: '0414-1234567'), $owner);

        $this->organization->refresh();

        $this->assertSame('Ferretería El Tornillo', $this->organization->name);
        $this->assertSame('0414-1234567', $this->organization->phone);
    }

    public function test_a_member_cannot_change_the_identity(): void
    {
        $this->subscribedOwner();
        $member = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->action()->handle($this->data(), $member);
    }

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

    public function test_the_brand_color_is_normalised(): void
    {
        $owner = $this->subscribedOwner();

        $this->action()->handle($this->data(brandColor: '#ABC'), $owner);

        $this->assertSame('#aabbcc', $this->organization->refresh()->brand_color);
    }

    public function test_the_logo_is_stored_and_exposed_as_a_url(): void
    {
        $owner = $this->subscribedOwner();

        $this->action()->handle($this->data(logo: UploadedFile::fake()->image('logo.png')), $owner);

        $this->organization->refresh();

        $this->assertNotNull($this->organization->logo_path);
        Storage::disk(OrganizationBrand::LOGO_DISK)->assertExists($this->organization->logo_path);

        $this->assertNotNull($this->action()->brandOf($this->organization)->logoUrl);
    }

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

    public function test_saving_without_a_logo_keeps_the_current_one(): void
    {
        $owner = $this->subscribedOwner();

        $this->action()->handle($this->data(logo: UploadedFile::fake()->image('logo.png')), $owner);
        $path = $this->organization->refresh()->logo_path;

        $this->action()->handle($this->data(phone: '0212-5550000'), $owner);

        $this->assertSame($path, $this->organization->refresh()->logo_path);
    }

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

    public function test_a_locked_slug_does_not_block_the_rest_of_the_form(): void
    {
        $owner = $this->subscribedOwner();

        $this->withPublishedCatalogs();

        $this->action()->handle($this->data(phone: '0212-5550000'), $owner);

        $this->assertSame('0212-5550000', $this->organization->refresh()->phone);
    }

    public function test_a_reserved_slug_is_rejected(): void
    {
        $owner = $this->subscribedOwner();

        $this->expectException(SlugTakenException::class);

        $this->action()->handle($this->data(slug: 'backoffice'), $owner);
    }

    public function test_a_slug_taken_by_another_organization_is_rejected(): void
    {
        $owner = $this->subscribedOwner();

        $this->expectException(SlugTakenException::class);

        $this->action()->handle($this->data(slug: $this->neighbourOrganization()->slug), $owner);
    }

    public function test_keeping_the_same_slug_is_not_a_collision(): void
    {
        $owner = $this->subscribedOwner();

        $this->action()->handle($this->data(name: 'Otro nombre'), $owner);

        $this->assertSame('ferreteria-el-tornillo', $this->organization->refresh()->slug);
        $this->assertSame('Otro nombre', $this->organization->name);
    }

    public function test_the_storefront_switch_is_part_of_the_identity(): void
    {
        $owner = $this->subscribedOwner();

        $this->assertFalse($this->organization->storefront_enabled);

        $this->action()->handle($this->data(storefrontEnabled: true), $owner);

        $this->assertTrue($this->organization->refresh()->storefront_enabled);
    }

    private function action(): UpdateOrganizationAction
    {
        return app(UpdateOrganizationAction::class);
    }

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
