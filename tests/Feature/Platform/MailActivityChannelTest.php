<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Events\MailActivity;
use App\Models\Organization;
use App\Models\PlatformUser;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class MailActivityChannelTest extends TestCase
{
    use ActsAsPlatformOperator, ReusesMigratedDatabase;

    public function test_only_an_active_operator_passes_the_channel_callback(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();

        $this->assertTrue($this->authorises($this->operator()));
        $this->assertFalse($this->authorises(PlatformUser::factory()->disabled()->create()));
        $this->assertFalse($this->authorises(PlatformUser::factory()->mustChangePassword()->create()));
        $this->assertFalse($this->authorises($member));
        $this->assertFalse($this->authorises(null));
    }

    public function test_the_channel_only_looks_at_the_platform_guard(): void
    {
        $broadcaster = app(BroadcastManager::class)->connection();
        $options = (new \ReflectionProperty(Broadcaster::class, 'channelOptions'))->getValue($broadcaster);

        $this->assertIsArray($options);
        $this->assertSame(['guards' => ['platform']], $options[MailActivity::CHANNEL] ?? null);
    }

    public function test_the_endpoint_signs_for_an_active_operator_session(): void
    {
        $this->useSigningBroadcaster();

        $this->asOperator();

        $this->subscribe()->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_the_endpoint_refuses_a_disabled_operator(): void
    {
        $this->useSigningBroadcaster();

        $this->asOperator(PlatformUser::factory()->disabled()->create());

        $this->subscribe()->assertForbidden();
    }

    public function test_the_endpoint_refuses_a_team_member_and_a_guest(): void
    {
        $this->useSigningBroadcaster();

        $organization = Organization::factory()->create();
        $member = $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();

        $this->actingAs($member);
        $this->subscribe()->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->subscribe()->assertForbidden();
    }

    private function authorises(mixed $user): bool
    {
        /** @var Broadcaster $broadcaster */
        $broadcaster = app(BroadcastManager::class)->connection();
        $callback = $broadcaster->getChannels()->all()[MailActivity::CHANNEL] ?? null;

        $this->assertIsCallable($callback, 'El canal de correos de la plataforma no está registrado.');

        return (bool) $callback($user);
    }

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
    private function subscribe(): TestResponse
    {
        return $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-'.MailActivity::CHANNEL,
        ]);
    }
}
