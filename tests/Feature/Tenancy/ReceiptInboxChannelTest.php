<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Events\ReceiptSubmitted;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * El canal de la bandeja, `organization.{orgId}.receipts` (`REQ-PAY-004`
 * RB-1, SP-7; [05 §7.7]).
 *
 * `BroadcastChannelIsolationTest` ya exige a todo canal que rechace a un
 * miembro de otra organización y acepte a uno de la propia. Esto añade lo que
 * aquel no sabe de este canal en concreto: que **un cliente** no entra, que un
 * miembro desactivado tampoco, que la suspensión no lo cierra (RB-9) y que el
 * nombre que difunde `ReceiptSubmitted` es el que se autoriza.
 *
 * Y lo prueba dos veces: llamando a la autorización registrada, y por HTTP,
 * contra `/broadcasting/auth` con un broadcaster que firma de verdad —el
 * `null` de los tests no comprueba nada y respondería que sí a todo—.
 */
final class ReceiptInboxChannelTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private const PATTERN = 'organization.{orgId}.receipts';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create());
    }

    /**
     * Los dos roles de la organización entran: los dos revisan comprobantes.
     */
    public function test_an_active_member_of_the_organization_is_authorised(): void
    {
        $owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->assertTrue($this->authorises($owner, $this->organization->getKey()));
        $this->assertTrue($this->authorises($member, (string) $this->organization->getKey()));
    }

    /**
     * SP-7 · el miembro del vecino no oye nuestra bandeja; ni con el
     * identificador escrito de otra forma («07»).
     */
    public function test_a_member_of_another_organization_is_refused(): void
    {
        $intruder = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());
        $ours = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->assertFalse($this->authorises($intruder, $this->organization->getKey()));
        $this->assertFalse($this->authorises($ours, '0'.$this->organization->getKey()));
    }

    /**
     * RB-1 · **un cliente** del negocio no entra, aunque su ficha sea de esta
     * organización: la bandeja es del equipo.
     */
    public function test_a_customer_of_the_organization_is_refused(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->findOrFail($layaway->customer_id));

        $this->assertFalse($this->authorises($customer, $this->organization->getKey()));
    }

    /**
     * Un miembro desactivado deja de oír la bandeja (miembro **activo**, [05
     * §7.7]); con la organización suspendida, el activo sigue oyéndola (RB-9).
     */
    public function test_a_disabled_member_is_refused_and_a_suspension_does_not_close_it(): void
    {
        $disabled = $this->withinOrganization($this->organization, fn (): User => User::factory()->create(['disabled_at' => now()]));
        $active = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->assertFalse($this->authorises($disabled, $this->organization->getKey()));
        $this->assertTrue($this->authorises($active->fresh() ?? $active, $this->organization->getKey()));
    }

    /**
     * Una organización cerrada ya no tiene equipo que oiga su bandeja: el
     * backoffice cierra la sesión en la siguiente petición, y este endpoint no
     * pasa por esa comprobación.
     */
    public function test_a_member_of_a_closed_organization_is_refused(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->organization->forceFill(['status' => OrganizationStatus::Closed])->save();

        $this->assertFalse($this->authorises($member->fresh() ?? $member, $this->organization->getKey()));
    }

    /**
     * Un nombre sin el prefijo `private-` se salta la comprobación de usuario
     * del broadcaster y llega a la autorización sin nadie: tiene que ser
     * `403`, no un `TypeError` que cualquiera pueda provocar sin sesión.
     */
    public function test_an_unprefixed_channel_name_without_a_session_is_forbidden(): void
    {
        $this->useSigningBroadcaster();

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => ReceiptSubmitted::channelFor((int) $this->organization->getKey()),
        ])->assertForbidden();
    }

    /**
     * El primero de los dos cerrojos contra el cliente: el canal declara
     * `guards => ['web']`, así que el broadcaster ni mira la sesión del
     * portal. Por HTTP no se distingue del segundo —`actingAs(..., 'customer')`
     * cambia el guard por defecto y el `instanceof` rechaza igual—, así que se
     * afirma la opción registrada.
     */
    public function test_the_channel_only_looks_at_the_team_guard(): void
    {
        $broadcaster = app(BroadcastManager::class)->connection();
        $options = (new \ReflectionProperty(Broadcaster::class, 'channelOptions'))->getValue($broadcaster);

        $this->assertIsArray($options);
        $this->assertSame(['guards' => ['web']], $options[self::PATTERN] ?? null);
    }

    /**
     * El canal que se difunde y el que se autoriza son el mismo.
     */
    public function test_the_event_is_broadcast_on_the_authorised_channel(): void
    {
        $this->assertSame(
            str_replace('{orgId}', '42', self::PATTERN),
            ReceiptSubmitted::channelFor(42),
        );
        $this->assertArrayHasKey(self::PATTERN, $this->channels());
    }

    /**
     * Por HTTP, con firma de verdad: el miembro recibe su firma; el del
     * vecino, un cliente con su sesión del portal y un anónimo, `403`.
     */
    public function test_the_authorisation_endpoint_signs_only_for_the_team_of_that_organization(): void
    {
        $this->useSigningBroadcaster();

        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $intruder = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->findOrFail($layaway->customer_id));
        $this->withoutOrganization();

        $this->subscribeTo($this->actingAs($member))->assertOk()->assertJsonStructure(['auth']);

        $this->app['auth']->forgetGuards();
        $this->subscribeTo($this->actingAs($intruder))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->subscribeTo($this->actingAs($customer, 'customer'))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->subscribeTo($this)->assertForbidden();
    }

    // -------------------------------------------------------------------

    private function authorises(User|Customer $actor, int|string $organizationId): bool
    {
        $callback = $this->channels()[self::PATTERN] ?? null;

        $this->assertIsCallable($callback, 'El canal de la bandeja no está registrado.');

        return (bool) $callback($actor, $organizationId);
    }

    /**
     * @return array<string, mixed>
     */
    private function channels(): array
    {
        /** @var Broadcaster $broadcaster */
        $broadcaster = app(BroadcastManager::class)->connection();

        return $broadcaster->getChannels()->all();
    }

    /**
     * El driver de Reverb con claves de prueba —firma en local, sin red— y
     * los canales registrados en él, que al arrancar se registraron en el
     * `null`.
     */
    private function useSigningBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        Broadcast::purge('reverb');
        require base_path('routes/channels.php');
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function subscribeTo(TestCase $client): TestResponse
    {
        return $client->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-'.ReceiptSubmitted::channelFor((int) $this->organization->getKey()),
        ]);
    }
}
