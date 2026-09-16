<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Tenancy\MissingTenantContextException;
use App\Models\AuditEntry;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * **La auditoría de una organización es invisible para otra**
 * (`REQ-BOF-005` SP-6, `REQ-ORG-002` RB-1).
 *
 * Va en la suite de tenancy y no con el resto de `REQ-BOF-005` por lo que dice
 * la convención del plan: toda tarea que crea una consulta del tenant añade su
 * caso aquí (`REQ-QAX-008` RB-2). Y este es de los que más importan: la
 * auditoría contiene **quién tocó el dinero de quién**, así que una fuga aquí
 * no es una lista de más — es el historial de un negocio en manos de otro.
 *
 * La tabla del paquete `spatie/laravel-activitylog` es global por diseño; lo
 * que la hace del tenant es `AuditEntry` con `BelongsToOrganization` y la
 * columna que `T-016` le añadió.
 */
final class AuditLogIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $mine;

    private Organization $neighbour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbour = $this->neighbourOrganization();
        $this->mine = Organization::factory()->create();

        $plan = Plan::factory()->create();

        foreach ([$this->neighbour, $this->mine] as $organization) {
            $this->withinOrganization($organization, fn () => Subscription::factory()->forPlan($plan)->create());
        }

        $this->withinOrganization($this->neighbour, fn () => $this->writeEntry('Ajuste de la vecina'));
        $this->withinOrganization($this->mine, fn () => $this->writeEntry('Ajuste mío'));

        $this->withoutOrganization();
    }

    /**
     * La precondición del test: sin dos organizaciones con entradas, esto no
     * probaría nada y pasaría igual (`REQ-QAX-003` RB-10).
     */
    public function test_the_scenario_has_two_organizations_with_entries(): void
    {
        $this->assertTrue($this->hasNeighbourOrganization());

        // Una entrada en cada una, contadas **por organización** y no en total:
        // un recuento global de la tabla estaría midiendo también lo que deje
        // cualquier otro test que no use `RefreshDatabase`, y este test tiene
        // que hablar de este escenario.
        foreach ([$this->mine, $this->neighbour] as $organization) {
            $this->assertSame(
                1,
                $this->withinOrganization($organization, fn (): int => AuditEntry::query()->count()),
            );
        }
    }

    public function test_a_query_only_sees_the_entries_of_the_active_organization(): void
    {
        $this->actingAsOrganization($this->mine);

        $this->assertSame(1, AuditEntry::query()->count());
        $this->assertSame('Ajuste mío', AuditEntry::query()->sole()->description);
    }

    public function test_the_neighbour_cannot_see_our_entries(): void
    {
        $this->actingAsOrganization($this->neighbour);

        $this->assertSame('Ajuste de la vecina', AuditEntry::query()->sole()->description);
    }

    /**
     * Y la fuga que de verdad importa: **por HTTP**, que es por donde la vería
     * una persona.
     */
    public function test_the_audit_page_never_shows_another_organizations_entries(): void
    {
        $owner = $this->withinOrganization($this->mine, fn (): User => User::factory()->owner()->create());

        $this->withoutOrganization();

        $this->actingAs($owner)
            ->get('/backoffice/auditoria')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('entries', 1)
                ->where('entries.0.description', 'Ajuste mío'));
    }

    /**
     * El identificador de una entrada ajena tampoco la alcanza: el global scope
     * la deja fuera de la consulta, no la esconde de la vista.
     */
    public function test_an_entry_of_another_organization_is_not_found_by_id(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbour,
            fn (): int => (int) AuditEntry::query()->firstOrFail()->getKey(),
        );

        $this->actingAsOrganization($this->mine);

        $this->assertNull(AuditEntry::query()->find($foreign));
    }

    /**
     * Sin organización fijada **lanza**, no devuelve todo (`REQ-ORG-002` SP-3).
     * Es la misma decisión que en el resto del tenant, y aquí el precio de
     * equivocarse sería enseñar la auditoría de toda la plataforma.
     */
    public function test_querying_the_audit_log_without_a_tenant_throws(): void
    {
        $this->withoutOrganization();

        $this->expectException(MissingTenantContextException::class);

        AuditEntry::query()->count();
    }

    private function writeEntry(string $description): void
    {
        AuditEntry::query()->create([
            'log_name' => AuditEntry::LOG_NAME,
            'event' => AuditEvent::SettingsUpdated->value,
            'description' => $description,
            'properties' => ['changed' => []],
        ]);
    }
}
