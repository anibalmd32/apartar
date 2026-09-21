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
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\AuditEntry;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Product;
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
     * Requisito 4 · **cupo del plan: pendiente de `T-067`**.
     *
     * No se simula y no se da por hecho. `LimitEnforcer` responde hoy «sin
     * límite» para la métrica `catalogs` porque `measure()` devuelve `null`
     * hasta que `T-066` escriba el conteo; adelantar la llamada aquí haría
     * cumplir una ficción y escribiría un contador que esa tarea tendría que
     * desmontar.
     *
     * Lo que este caso fija es el **estado de hoy**: publicar no consume cupo
     * ni lo comprueba. El día que `T-067` inserte su línea, este test se cae y
     * quien la inserte tendrá que venir aquí a escribir el de verdad — que es
     * exactamente lo que se quiere que ocurra.
     */
    public function test_the_plan_quota_requirement_is_not_enforced_yet(): void
    {
        // Sin comentarios: la acción **nombra** la llamada para decir dónde
        // irá, y un `grep` sobre el texto crudo confundiría la nota con la
        // implementación.
        $this->assertStringNotContainsString(
            'assertCanCreate(',
            php_strip_whitespace(base_path('app/Actions/Catalog/PublishCatalogAction.php')),
            'El cupo `catalogs` es de T-067. Si ya lo has insertado, sustituye este caso por el test de SP-3: '
            .'publicar con el cupo agotado responde 422 con el plan que lo desbloquea.',
        );

        // Y mientras tanto publicar funciona sin contador de por medio: no se
        // materializa ninguna fila de `usage_counters` para la métrica.
        $catalog = $this->ready();

        $this->publish()->handle($catalog, $this->data(), $this->actor);

        $this->assertSame(CatalogVisibility::Listed, $catalog->refresh()->visibility());
        $this->assertFalse(UsageCounter::query()->where('metric', UsageMetric::Catalogs)->exists());
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

    private function data(): PublishCatalogData
    {
        return new PublishCatalogData(isListed: true, expiresAt: null);
    }

    private function publish(): PublishCatalogAction
    {
        return app(PublishCatalogAction::class);
    }
}
