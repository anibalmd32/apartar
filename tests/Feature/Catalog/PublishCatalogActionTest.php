<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\PublishCatalogAction;
use App\Actions\Catalog\PublishCatalogData;
use App\Actions\Catalog\UnpublishCatalogAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\CatalogNotPublishableException;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\AuditEntry;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los seis requisitos para publicar un catálogo (`REQ-CAT-022` RB-4).
 *
 * **Hay un test por cada uno de los seis**, como pide el gate — y el cuarto,
 * el cupo del plan, está declarado pendiente en vez de inventado: su
 * comprobación es de `T-067` y la métrica `catalogs` responde hoy «sin
 * límite» a propósito. El test existe para que el hueco se vea en la suite y
 * no en la spec.
 *
 * Lo demás que se prueba aquí es lo que no se puede probar en la pantalla: que
 * la expiración se **deriva** sin que corra nada (RB-6), que despublicar no
 * recicla el enlace (SP-7) y que los tres eventos de auditoría se escriben.
 */
final class PublishCatalogActionTest extends TestCase
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
        // `T-067` · crear, publicar y componer preguntan al plan.
        $this->subscribeWithoutLimits();

        $this->actor = User::factory()->owner()->create();
    }

    // ------------------------------------------------------------------
    // Los seis requisitos de RB-4
    // ------------------------------------------------------------------

    /**
     * Requisito 1 · SP-1 · sin un producto **publicado** dentro, no se
     * publica. Un borrador cuenta para la composición (`REQ-CAT-023` RB-5) y
     * no para esto: el enlace abriría un catálogo vacío.
     */
    public function test_it_requires_at_least_one_published_product(): void
    {
        $catalog = $this->ready();
        $catalog->products()->detach();
        $catalog->products()->attach(Product::factory()->create(), ['position' => 1]);

        $missing = $this->reject($catalog);

        $this->assertArrayHasKey('products', $missing);
        $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
    }

    /**
     * Requisito 2 · SP-2 · sin cuenta de cobro activa tampoco. Una
     * desactivada no vale: es lo que mide `REQ-ORG-007` RB-4.
     */
    public function test_it_requires_an_active_payment_account(): void
    {
        $catalog = $this->ready();
        PaymentAccount::query()->update(['is_active' => false]);

        $missing = $this->reject($catalog);

        $this->assertArrayHasKey('payment_accounts', $missing);
        $this->assertStringContainsString('cuenta de cobro', $missing['payment_accounts']);
    }

    /**
     * Requisito 3 · SP-5 · una caducidad ya pasada es `422`: el enlace diría
     * que la campaña terminó desde el primer minuto.
     */
    public function test_it_rejects_an_expiration_that_already_passed(): void
    {
        $catalog = $this->ready();

        $missing = $this->reject($catalog, new PublishCatalogData(
            isListed: true,
            expiresAt: CarbonImmutable::now()->subDay(),
        ));

        $this->assertArrayHasKey('expires_at', $missing);
        $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
    }

    /**
     * Requisito 3 · SP-8 · y republicar un catálogo **expirado** sin mover la
     * fecha es el mismo rechazo visto desde el otro lado. No hace falta una
     * segunda comprobación: es la misma condición, y esa es justamente la
     * razón de que esté escrita una sola vez.
     */
    public function test_republishing_an_expired_catalog_without_moving_the_date_is_rejected(): void
    {
        $catalog = $this->ready();
        $expired = CarbonImmutable::now()->subDay();

        $catalog->forceFill(['published_at' => now()->subMonth(), 'expires_at' => $expired])->save();

        $this->assertSame(CatalogVisibility::Expired, $catalog->visibility());

        $missing = $this->reject($catalog, new PublishCatalogData(isListed: true, expiresAt: $expired));

        $this->assertArrayHasKey('expires_at', $missing);
        $this->assertSame(CatalogVisibility::Expired, $catalog->refresh()->visibility());

        // Y moverla al futuro la revive sin tocar nada más: es lo único que
        // SP-8 pide hacer.
        $this->publish()->handle($catalog, new PublishCatalogData(
            isListed: true,
            expiresAt: CarbonImmutable::now()->addWeek(),
        ), $this->actor);

        $this->assertSame(CatalogVisibility::Listed, $catalog->refresh()->visibility());
    }

    /**
     * Requisito 4 · SP-3 de `REQ-BOF-024` (`T-067`) · con el cupo
     * `catalogs` agotado, publicar responde con el plan que lo desbloquea y
     * con la salida sin cambiar de plan: despublicar otro. **Solo cuentan los
     * publicados**: un borrador no ocupa (`REQ-BOF-024` RB-8), y despublicar
     * libera el sitio (`REQ-CAT-022` RB-7).
     */
    public function test_publishing_with_the_quota_exhausted_names_the_plan_that_unlocks_it(): void
    {
        $this->capCatalogsAt(1);
        Plan::factory()->create(['code' => 'pro-sp3', 'name' => 'Pro', 'position' => 2, 'max_catalogs' => 10]);

        $first = $this->ready();
        $this->ready();
        $second = $this->ready();

        $this->publish()->handle($first, $this->data(), $this->actor);

        try {
            $this->publish()->handle($second, $this->data(), $this->actor);
            $this->fail('Se publicó un segundo catálogo con el cupo de uno.');
        } catch (PlanLimitReachedException $exception) {
            $this->assertSame(UsageMetric::Catalogs, $exception->metric);
            $this->assertSame(1, $exception->used);
            $this->assertSame(1, $exception->limit);
            $this->assertSame('pro-sp3', $exception->minimumPlan?->code);
            $this->assertStringContainsString('despublicar otro catálogo', $exception->toArray()['suggestion']);
        }

        $this->assertSame(CatalogVisibility::Draft, $second->refresh()->visibility());

        // Despublicar libera, y entonces cabe.
        app(UnpublishCatalogAction::class)->handle($first->refresh(), $this->actor);
        $this->publish()->handle($second, $this->data(), $this->actor);

        $this->assertSame(CatalogVisibility::Listed, $second->refresh()->visibility());
        $this->assertSame(1, $this->catalogsCounter());
    }

    /**
     * El panel de publicación avisa del cupo **antes** de pulsar, con la misma
     * pregunta al plan; y no al que ya está publicado, que no ocupa nada
     * nuevo (revisión de `T-067`).
     */
    public function test_the_publish_panel_shows_the_quota_before_pressing(): void
    {
        $this->capCatalogsAt(1);
        $published = $this->ready();
        $draft = $this->ready();
        $this->publish()->handle($published, $this->data(), $this->actor);

        $this->assertArrayHasKey('plan', $this->publish()->blockersFor($draft->refresh()));
        $this->assertStringContainsString('despublicar otro catálogo', $this->publish()->blockersFor($draft)['plan']);
        $this->assertArrayNotHasKey('plan', $this->publish()->blockersFor($published->refresh()));
    }

    /**
     * Cambiar la fecha o la vitrina de uno **ya publicado** no ocupa nada
     * nuevo: con el cupo justo, se puede.
     */
    public function test_editing_a_published_catalog_does_not_ask_for_a_slot(): void
    {
        $this->capCatalogsAt(1);
        $catalog = $this->ready();

        $this->publish()->handle($catalog, $this->data(), $this->actor);
        $this->publish()->handle($catalog->refresh(), new PublishCatalogData(isListed: false, expiresAt: CarbonImmutable::now()->addWeek()), $this->actor);

        $this->assertSame(CatalogVisibility::Unlisted, $catalog->refresh()->visibility());
    }

    /**
     * RB-8 de `REQ-SUB-004` · con la suscripción suspendida no se publica
     * nada, haya sitio o no.
     */
    public function test_a_suspended_subscription_cannot_publish(): void
    {
        Subscription::query()->update(['status' => SubscriptionStatus::Suspended->value]);

        $this->expectException(CreationSuspendedException::class);

        $this->publish()->handle($this->ready(), $this->data(), $this->actor);
    }

    /**
     * `REQ-SUB-003` RB-3, SP-6 · el cupo `catalogs` se mueve en la transacción
     * de cada publicación: publicar un borrador suma uno, cambiar la fecha o la
     * vitrina de uno ya publicado no suma nada, despublicar resta uno, y
     * republicar vuelve a sumar.
     */
    public function test_publishing_and_unpublishing_move_the_catalogs_counter(): void
    {
        UsageCounter::factory()->forMetric(UsageMetric::Catalogs)->create();
        $catalog = $this->ready();

        $this->publish()->handle($catalog, $this->data(), $this->actor);
        $this->assertSame(1, $this->catalogsCounter());

        $this->publish()->handle($catalog, new PublishCatalogData(isListed: false, expiresAt: CarbonImmutable::now()->addWeek()), $this->actor);
        $this->assertSame(1, $this->catalogsCounter());

        app(UnpublishCatalogAction::class)->handle($catalog, $this->actor);
        $this->assertSame(0, $this->catalogsCounter());

        app(UnpublishCatalogAction::class)->handle($catalog, $this->actor);
        $this->assertSame(0, $this->catalogsCounter());

        $this->publish()->handle($catalog, $this->data(), $this->actor);
        $this->assertSame(1, $this->catalogsCounter());
    }

    /**
     * Uno caducado ya no cuenta para la medida: republicarlo con fecha nueva
     * suma uno, y despublicarlo no resta nada —el recálculo es quien descuenta
     * lo que caduca (`UsageMetric::shrinksWithTime()`)—.
     */
    public function test_an_expired_catalog_moves_the_counter_like_a_draft(): void
    {
        UsageCounter::factory()->forMetric(UsageMetric::Catalogs)->create();
        $catalog = $this->ready();
        $catalog->forceFill(['published_at' => now()->subWeek(), 'expires_at' => now()->subDay()])->save();

        app(UnpublishCatalogAction::class)->handle($catalog, $this->actor);
        $this->assertSame(0, $this->catalogsCounter());

        $catalog->forceFill(['published_at' => now()->subWeek(), 'expires_at' => now()->subDay()])->save();
        $this->publish()->handle($catalog, new PublishCatalogData(isListed: true, expiresAt: CarbonImmutable::now()->addWeek()), $this->actor);
        $this->assertSame(1, $this->catalogsCounter());
    }

    /**
     * Requisito 5 · SP-4 · el correo del **dueño** tiene que estar verificado
     * (`REQ-ORG-001` RB-5). El resto del backoffice funciona sin verificar;
     * esto no, porque es el paso en que empieza a entrar gente de fuera.
     */
    public function test_it_requires_the_owner_email_to_be_verified(): void
    {
        $catalog = $this->ready();

        User::query()->update(['email_verified_at' => null]);

        $missing = $this->reject($catalog);

        $this->assertArrayHasKey('owner_email', $missing);
        $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
    }

    /**
     * Requisito 6 · SP-9 · con la organización suspendida es `403` y no `422`:
     * no falta un dato, falta estar al día. La ruta ya lo bloquea; la acción
     * lo comprueba igual, porque RB-4 lo pide **en la acción** y un comando no
     * pasa por el middleware.
     */
    public function test_a_suspended_organization_cannot_publish(): void
    {
        $catalog = $this->ready();

        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $this->actingAsOrganization($this->organization->refresh());

        $this->expectException(CreationSuspendedException::class);

        try {
            $this->publish()->handle($catalog, $this->data(), $this->actor);
        } finally {
            $this->assertSame(CatalogVisibility::Draft, $catalog->refresh()->visibility());
        }
    }

    /**
     * Y los pendientes llegan **todos juntos**: quien publica un catálogo
     * vacío y sin cuenta de cobro arregla las dos cosas de una pasada.
     */
    public function test_every_unmet_requirement_travels_together(): void
    {
        $catalog = Catalog::factory()->create();

        User::query()->update(['email_verified_at' => null]);

        try {
            $this->publish()->handle($catalog, $this->data(), $this->actor);
            $this->fail('Se esperaba que la publicación se rechazara y no lo hizo.');
        } catch (CatalogNotPublishableException $exception) {
            $this->assertSame(['products', 'payment_accounts', 'owner_email'], array_keys($exception->missing));

            // Y el resumen los nombra los tres de una vez, que es lo que lee
            // quien publicó desde el listado y no tiene los campos delante.
            $this->assertStringContainsString('al menos un producto publicado dentro', $exception->getMessage());
            $this->assertStringContainsString('una cuenta de cobro activa', $exception->getMessage());
            $this->assertStringContainsString('verificar el correo del dueño', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Lo que la publicación escribe
    // ------------------------------------------------------------------

    /**
     * RB-3 · `is_listed` decide la vitrina, no el acceso: los dos modos son
     * publicados, y el estado derivado los distingue.
     */
    public function test_it_publishes_inside_or_outside_the_storefront(): void
    {
        $listed = $this->ready();
        $unlisted = $this->ready();

        $this->publish()->handle($listed, $this->data(), $this->actor);
        $this->publish()->handle($unlisted, new PublishCatalogData(isListed: false, expiresAt: null), $this->actor);

        $this->assertSame(CatalogVisibility::Listed, $listed->refresh()->visibility());
        $this->assertSame(CatalogVisibility::Unlisted, $unlisted->refresh()->visibility());
        $this->assertTrue($unlisted->visibility()->isPubliclyAccessible());
    }

    /**
     * RB-6 · **la expiración se deriva y no la programa nadie.** No se
     * adelanta el reloj con un job: se adelanta el reloj de verdad, y el
     * catálogo caduca solo.
     */
    public function test_the_expiration_derives_without_any_job(): void
    {
        $catalog = $this->ready();

        $this->publish()->handle($catalog, new PublishCatalogData(
            isListed: true,
            expiresAt: CarbonImmutable::now()->addDay(),
        ), $this->actor);

        $this->assertSame(CatalogVisibility::Listed, $catalog->refresh()->visibility());

        $this->travel(2)->days();

        $this->assertSame(CatalogVisibility::Expired, $catalog->refresh()->visibility());
    }

    /**
     * RB-7 · **republicar tras despublicar estrena fecha**, porque entre medias
     * la URL estuvo dando `404`: lo que `published_at` dice es desde cuándo
     * circula este enlace, no desde cuándo existe el catálogo.
     *
     * Lo que despublicar conserva —el enlace, la composición, la vitrina— lo
     * prueba `UnpublishCatalogActionTest`.
     */
    public function test_republishing_after_retiring_starts_a_new_date(): void
    {
        $catalog = $this->ready();

        $this->publish()->handle($catalog, $this->data(), $this->actor);

        $first = $catalog->refresh()->published_at;

        app(UnpublishCatalogAction::class)->handle($catalog, $this->actor);

        $this->travel(2)->days();

        $this->publish()->handle($catalog->refresh(), $this->data(), $this->actor);

        $this->assertNotNull($first);
        $this->assertNotNull($catalog->refresh()->published_at);
        $this->assertTrue($catalog->published_at->greaterThan($first));
    }

    // ------------------------------------------------------------------
    // Auditoría (RB-10)
    // ------------------------------------------------------------------

    /**
     * Los tres eventos declarados en `AuditEvent` con estas dos acciones como
     * emisoras se escriben de verdad.
     */
    public function test_it_audits_publishing_the_expiration_and_unpublishing(): void
    {
        $catalog = $this->ready();

        $this->publish()->handle($catalog, new PublishCatalogData(
            isListed: true,
            expiresAt: CarbonImmutable::now()->addWeek(),
        ), $this->actor);

        app(UnpublishCatalogAction::class)->handle($catalog, $this->actor);

        foreach ([AuditEvent::CatalogPublished, AuditEvent::CatalogExpirationScheduled, AuditEvent::CatalogUnpublished] as $event) {
            $this->assertTrue(
                AuditEntry::query()->where('event', $event->value)->exists(),
                sprintf('Falta la entrada de auditoría de %s.', $event->value),
            );
        }

        $entry = AuditEntry::query()->where('event', AuditEvent::CatalogPublished->value)->sole();

        $this->assertStringContainsString($catalog->name, $entry->description);
    }

    /**
     * Y volver a pulsar sin cambiar nada **no escribe una segunda entrada**:
     * llenar el registro de cambios que no ocurrieron es peor que dejarlo
     * incompleto, porque se lee igual de bien.
     */
    public function test_publishing_twice_without_changes_writes_nothing_new(): void
    {
        $catalog = $this->ready();

        $this->publish()->handle($catalog, $this->data(), $this->actor);
        $this->publish()->handle($catalog->refresh(), $this->data(), $this->actor);

        $this->assertSame(1, AuditEntry::query()->where('event', AuditEvent::CatalogPublished->value)->count());
    }

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    /**
     * Un catálogo que reúne los cinco requisitos comprobables: un producto
     * publicado dentro, una cuenta de cobro activa y el owner verificado.
     */
    private function ready(): Catalog
    {
        if (! PaymentAccount::query()->exists()) {
            PaymentAccount::factory()->create();
        }

        $catalog = Catalog::factory()->create();
        $catalog->products()->attach(Product::factory()->published()->create(), ['position' => 1]);

        return $catalog;
    }

    /**
     * Publica esperando el rechazo, y devuelve la lista de lo que falta.
     *
     * @return array<string, string>
     */
    private function reject(Catalog $catalog, ?PublishCatalogData $data = null): array
    {
        try {
            $this->publish()->handle($catalog, $data ?? $this->data(), $this->actor);
        } catch (CatalogNotPublishableException $exception) {
            return $exception->missing;
        }

        $this->fail('Se esperaba que la publicación se rechazara y no lo hizo.');
    }

    private function capCatalogsAt(int $limit): void
    {
        Subscription::query()->firstOrFail()->plan->forceFill(['max_catalogs' => $limit])->save();
    }

    private function catalogsCounter(): int
    {
        return (int) UsageCounter::query()->where('metric', UsageMetric::Catalogs)->value('value');
    }

    private function data(): PublishCatalogData
    {
        return new PublishCatalogData(isListed: true, expiresAt: null);
    }

    private function publish(): PublishCatalogAction
    {
        return app(PublishCatalogAction::class);
    }
}
