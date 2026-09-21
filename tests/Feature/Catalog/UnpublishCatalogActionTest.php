<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\PublishCatalogAction;
use App\Actions\Catalog\PublishCatalogData;
use App\Actions\Catalog\UnpublishCatalogAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\AuditEntry;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retirar un catálogo de circulación (`REQ-CAT-022` RB-7, RB-8, RB-9, SP-7).
 *
 * La acción escribe una columna, así que lo que se prueba aquí es sobre todo
 * **lo que no hace**: no recicla el enlace, no toca la composición, no mueve
 * `is_listed` ni `expires_at`, y no borra nada de lo que cuelga del catálogo.
 * Las tablas que RB-8 y RB-9 protegen —apartados y suscripciones— no existen
 * todavía, así que la garantía de hoy es que **no hay ningún recorrido que las
 * busque**: es lo único comprobable, y es justo lo que se rompería sin darse
 * cuenta el día que esas tablas lleguen.
 */
final class UnpublishCatalogActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);

        $this->actor = User::factory()->owner()->create();
    }

    /**
     * RB-7 y SP-7 · vuelve a borrador, **conserva su enlace** y deja la
     * composición como estaba.
     */
    public function test_it_returns_to_draft_keeping_the_slug_and_the_products(): void
    {
        $catalog = $this->published();
        $slug = $catalog->slug;

        $this->unpublish()->handle($catalog, $this->actor);

        $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
        $this->assertNull($catalog->published_at);
        $this->assertSame($slug, $catalog->slug);
        $this->assertSame(1, $catalog->products()->count());
    }

    /**
     * Y **no apaga la campaña entera**: `is_listed` y `expires_at` se quedan
     * como se dejaron, para que republicar encuentre lo que había. Si la fecha
     * ya pasó, quien lo dirá es `PublishCatalogAction` con su `422` (SP-8), no
     * un borrado silencioso aquí.
     */
    public function test_it_leaves_the_storefront_flag_and_the_expiration_alone(): void
    {
        $expiresAt = CarbonImmutable::now()->addWeek();

        $catalog = $this->published(new PublishCatalogData(isListed: false, expiresAt: $expiresAt));

        $this->unpublish()->handle($catalog, $this->actor);

        $catalog->refresh();

        $this->assertFalse($catalog->is_listed);
        $this->assertNotNull($catalog->expires_at);
        $this->assertSame($expiresAt->toDateString(), $catalog->expires_at->toDateString());
    }

    /**
     * Un catálogo **expirado** también se despublica: sigue teniendo su
     * `published_at`, y despublicarlo es lo que lo devuelve a borrador de
     * verdad.
     */
    public function test_an_expired_catalog_can_still_be_retired(): void
    {
        $catalog = $this->published();
        $catalog->forceFill(['expires_at' => now()->subDay()])->save();

        $this->assertSame(CatalogVisibility::Expired, $catalog->visibility());

        $this->unpublish()->handle($catalog, $this->actor);

        $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
    }

    /**
     * RB-10 · se audita, con el estado derivado a los dos lados para que la
     * entrada se lea sin interpretar fechas.
     */
    public function test_it_is_audited_with_the_derived_state_on_both_sides(): void
    {
        $catalog = $this->published();

        $this->unpublish()->handle($catalog, $this->actor);

        $entry = AuditEntry::query()->where('event', AuditEvent::CatalogUnpublished->value)->sole();

        $this->assertStringContainsString($catalog->name, $entry->description);

        /** @var array<string, array{before?: mixed, after?: mixed}> $changes */
        $changes = $entry->properties['changed'] ?? [];

        $this->assertSame(CatalogVisibility::Listed->value, $changes['visibility']['before'] ?? null);
        $this->assertSame(CatalogVisibility::Draft->value, $changes['visibility']['after'] ?? null);
    }

    /**
     * Repetir sobre un borrador no es un error ni escribe entrada: suele ser
     * el segundo clic de quien no vio el primero.
     */
    public function test_retiring_a_draft_writes_nothing(): void
    {
        $catalog = Catalog::factory()->create();

        $this->unpublish()->handle($catalog, $this->actor);

        $this->assertSame(0, AuditEntry::query()->where('event', AuditEvent::CatalogUnpublished->value)->count());
    }

    /**
     * **Despublicar no comprueba la suspensión**, al revés que publicar, y es
     * deliberado: retirar algo de la calle nunca es crear. Quien cierra la
     * puerta en la web es la ruta, que no declara `during-suspension`; la
     * acción no añade un segundo cerrojo que dejaría a un negocio suspendido
     * sin poder apagar una campaña con precios viejos.
     */
    public function test_the_action_itself_does_not_block_a_suspended_organization(): void
    {
        $catalog = $this->published();

        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $this->actingAsOrganization($this->organization->refresh());

        $this->unpublish()->handle($catalog, $this->actor);

        $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
    }

    /**
     * Un catálogo publicado de verdad, por su propia acción.
     */
    private function published(?PublishCatalogData $data = null): Catalog
    {
        PaymentAccount::factory()->create();

        $catalog = Catalog::factory()->create();
        $catalog->products()->attach(Product::factory()->published()->create(), ['position' => 1]);

        app(PublishCatalogAction::class)->handle(
            $catalog,
            $data ?? new PublishCatalogData(isListed: true, expiresAt: null),
            $this->actor,
        );

        return $catalog->refresh();
    }

    private function unpublish(): UnpublishCatalogAction
    {
        return app(UnpublishCatalogAction::class);
    }
}
