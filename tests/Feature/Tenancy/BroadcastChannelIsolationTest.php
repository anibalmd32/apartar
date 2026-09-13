<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

/**
 * Aislamiento de los canales de broadcast (`REQ-QAX-008` RB-3 y SP-7).
 *
 * Los tres canales de [05 §7.7] llegan en H7, con el chat y los comprobantes.
 * Este archivo no espera a que existan, y no porque sobre trabajo: SP-7 dice
 * que una fuga por un canal **no la detectaría ningún test HTTP**, y un archivo
 * vacío con un `TODO` depende de que alguien se acuerde el día que registre el
 * primero.
 *
 * Lo que se afirma hoy es la regla, no los canales: **ningún canal registrado
 * carece de comprobación de pertenencia**. Hoy no hay ninguno y la aserción
 * pasa sobre el conjunto vacío; el día que se registre
 * `organization.{orgId}.receipts` sin autorizar, este archivo falla sin que
 * nadie haya tenido que acordarse de nada. Que el mecanismo funciona de verdad
 * lo prueban los canales inyectados de más abajo, que es lo que distingue esta
 * vacuidad de un test apagado.
 *
 * Como en `CrossTenantAccessTest`, lo que el mecanismo no sabe interpretar
 * **es una violación**, no un caso omitido.
 */
final class BroadcastChannelIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Nombres de parámetro que el mecanismo sabe rellenar con una
     * organización.
     *
     * Un canal con otro parámetro —`{conversation}`, `{receipt}`— hace fallar
     * la suite hasta que se le enseñe a construirlo, que es la conversación que
     * debe ocurrir antes de exponer un canal nuevo.
     *
     * @var list<string>
     */
    private const ORGANIZATION_PARAMETERS = ['orgId', 'organizationId', 'organization'];

    /**
     * RB-3 · ningún canal registrado deja entrar a quien no pertenece.
     */
    public function test_no_registered_channel_leaks_across_organizations(): void
    {
        $this->assertSame([], $this->channelViolations());
    }

    /**
     * El archivo de canales, si existe, tiene que estar enrutado.
     *
     * Registrar canales en un archivo que nadie carga no da ningún error: el
     * canal simplemente no existe, la autorización se deniega y alguien "lo
     * arregla" abriéndolo por otro lado. Esta aserción convierte ese silencio
     * en un fallo.
     */
    public function test_the_channels_file_is_routed_when_it_exists(): void
    {
        if (! file_exists(base_path('routes/channels.php'))) {
            $this->assertSame([], $this->registeredChannels(), 'Hay canales registrados sin archivo de canales.');

            return;
        }

        $this->assertNotSame(
            [],
            $this->registeredChannels(),
            'routes/channels.php existe pero no está enrutado en bootstrap/app.php.',
        );
    }

    /**
     * Un canal que autoriza a cualquiera —el descuido más fácil de cometer,
     * porque durante el desarrollo funciona— hace fallar la suite.
     */
    public function test_a_permissive_channel_breaks_the_mechanism(): void
    {
        Broadcast::channel('organization.{orgId}.abierto', fn (): bool => true);

        $violations = $this->channelViolations();

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('organization.{orgId}.abierto', $violations[0]);
        $this->assertStringContainsString('autoriza', $violations[0]);
    }

    /**
     * Y un canal cuyo nombre no lleva organización tampoco pasa: si el
     * mecanismo no puede situar al suscriptor en un negocio, no puede
     * comprobar que pertenece a él.
     */
    public function test_a_channel_without_an_organization_breaks_the_mechanism(): void
    {
        Broadcast::channel('conversation.{conversationId}', fn (): bool => false);

        $violations = $this->channelViolations();

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('conversation.{conversationId}', $violations[0]);
    }

    /**
     * El control positivo, sin el cual lo anterior podría estar pasando porque
     * el mecanismo rechaza todo: un canal bien hecho —el de RB-3, miembro
     * activo de esa organización— no produce ninguna violación.
     */
    public function test_a_channel_that_checks_membership_passes(): void
    {
        Broadcast::channel(
            'organization.{orgId}.receipts',
            fn (User $user, int $orgId): bool => $user->organization_id === $orgId,
        );

        $this->assertSame([], $this->channelViolations());
    }

    /**
     * Recorre los canales registrados y devuelve lo que está mal en cada uno.
     *
     * Se comprueban las dos direcciones a propósito. Que el canal rechace al
     * ajeno es la regla; que acepte al propio es lo que evita que la regla se
     * cumpla por la vía de rechazar a todo el mundo, que pasaría el test y
     * rompería el producto.
     *
     * @return list<string>
     */
    private function channelViolations(): array
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $member = $this->withinOrganization($own, fn (): User => User::factory()->create());

        $this->withoutOrganization();

        $violations = [];

        foreach ($this->registeredChannels() as $name => $callback) {
            $outsider = $this->argumentsFor($name, $neighbour);

            if ($outsider === null) {
                $violations[] = sprintf(
                    '%s · la suite no sabe con qué organización comprobar la pertenencia; '
                    .'enséñale antes de registrar el canal.',
                    $name,
                );

                continue;
            }

            if ($this->authorises($name, $callback, $member, $outsider)) {
                $violations[] = sprintf('%s · autoriza a un miembro de otra organización.', $name);
            }

            /** @var list<mixed> $inside */
            $inside = $this->argumentsFor($name, $own);

            if (! $this->authorises($name, $callback, $member, $inside)) {
                $violations[] = sprintf('%s · rechaza a un miembro de su propia organización.', $name);
            }
        }

        return $violations;
    }

    /**
     * Canales registrados en el *broadcaster* por defecto.
     *
     * @return array<string, mixed>
     */
    private function registeredChannels(): array
    {
        $broadcaster = $this->app->make(BroadcastFactory::class)->connection();

        if (! $broadcaster instanceof Broadcaster) {
            $this->fail('El broadcaster no expone sus canales; la suite no puede comprobarlos.');
        }

        /** @var array<string, mixed> $channels */
        $channels = $broadcaster->getChannels()->all();

        return $channels;
    }

    /**
     * Argumentos con los que se invoca la autorización, o `null` si el nombre
     * del canal no dice a qué organización pertenece.
     *
     * @return list<mixed>|null
     */
    private function argumentsFor(string $channel, Organization $organization): ?array
    {
        preg_match_all('/\{([^}]+)\}/', $channel, $matches);

        if ($matches[1] === []) {
            return null;
        }

        $arguments = [];

        foreach ($matches[1] as $parameter) {
            if (! in_array($parameter, self::ORGANIZATION_PARAMETERS, true)) {
                return null;
            }

            $arguments[] = $organization->getKey();
        }

        return $arguments;
    }

    /**
     * Ejecuta la autorización del canal, venga como *closure* o como clase.
     *
     * @param  list<mixed>  $arguments
     */
    private function authorises(string $channel, mixed $callback, User $user, array $arguments): bool
    {
        if (is_callable($callback)) {
            return (bool) $callback($user, ...$arguments);
        }

        if (is_string($callback) && class_exists($callback) && method_exists($callback, 'join')) {
            return (bool) $this->app->make($callback)->join($user, ...$arguments);
        }

        $this->fail(sprintf('La autorización del canal %s no es invocable.', $channel));
    }
}
