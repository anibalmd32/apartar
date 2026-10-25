<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

final class BroadcastChannelIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private const ORGANIZATION_PARAMETERS = ['orgId', 'organizationId', 'organization'];

    public function test_no_registered_channel_leaks_across_organizations(): void
    {
        $this->assertSame([], $this->channelViolations());
    }

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

    public function test_a_permissive_channel_breaks_the_mechanism(): void
    {
        Broadcast::channel('organization.{orgId}.abierto', fn (): bool => true);

        $violations = $this->channelViolations();

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('organization.{orgId}.abierto', $violations[0]);
        $this->assertStringContainsString('autoriza', $violations[0]);
    }

    public function test_a_channel_without_an_organization_breaks_the_mechanism(): void
    {
        Broadcast::channel('conversation.{conversationId}', fn (): bool => false);

        $violations = $this->channelViolations();

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('conversation.{conversationId}', $violations[0]);
    }

    public function test_a_channel_that_checks_membership_passes(): void
    {
        Broadcast::channel(
            'organization.{orgId}.receipts',
            fn (User $user, int $orgId): bool => $user->organization_id === $orgId,
        );

        $this->assertSame([], $this->channelViolations());
    }

    /**
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
     * @return list<mixed>|null
     */
    private function argumentsFor(string $channel, Organization $organization): ?array
    {
        preg_match_all('/\{([^}]+)\}/', $channel, $matches);

        if ($matches[1] === []) {
            return null;
        }

        if (array_intersect($matches[1], self::ORGANIZATION_PARAMETERS) === []) {
            return null;
        }

        $arguments = [];

        foreach ($matches[1] as $parameter) {
            $arguments[] = match (true) {
                in_array($parameter, self::ORGANIZATION_PARAMETERS, true) => $organization->getKey(),
                $parameter === 'conversationId' => $this->conversationIn($organization),
                default => null,
            };

            if ($arguments[array_key_last($arguments)] === null) {
                return null;
            }
        }

        return $arguments;
    }

    private function conversationIn(Organization $organization): int
    {
        $id = $this->withinOrganization($organization, fn (): int => (int) Conversation::factory()->create()->getKey());
        $this->withoutOrganization();

        return $id;
    }

    /**
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
