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
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ReceiptInboxChannelTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private const PATTERN = 'organization.{orgId}.receipts';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create());
    }

    public function test_an_active_member_of_the_organization_is_authorised(): void
    {
        $owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->assertTrue($this->authorises($owner, $this->organization->getKey()));
        $this->assertTrue($this->authorises($member, (string) $this->organization->getKey()));
    }

    public function test_a_member_of_another_organization_is_refused(): void
    {
        $intruder = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());
        $ours = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->assertFalse($this->authorises($intruder, $this->organization->getKey()));
        $this->assertFalse($this->authorises($ours, '0'.$this->organization->getKey()));
    }

    public function test_a_customer_of_the_organization_is_refused(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->findOrFail($layaway->customer_id));

        $this->assertFalse($this->authorises($customer, $this->organization->getKey()));
    }

    public function test_a_disabled_member_is_refused_and_a_suspension_does_not_close_it(): void
    {
        $disabled = $this->withinOrganization($this->organization, fn (): User => User::factory()->create(['disabled_at' => now()]));
        $active = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->assertFalse($this->authorises($disabled, $this->organization->getKey()));
        $this->assertTrue($this->authorises($active->fresh() ?? $active, $this->organization->getKey()));
    }

    public function test_a_member_of_a_closed_organization_is_refused(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->organization->forceFill(['status' => OrganizationStatus::Closed])->save();

        $this->assertFalse($this->authorises($member->fresh() ?? $member, $this->organization->getKey()));
    }

    public function test_an_unprefixed_channel_name_without_a_session_is_forbidden(): void
    {
        $this->useSigningBroadcaster();

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => ReceiptSubmitted::channelFor((int) $this->organization->getKey()),
        ])->assertForbidden();
    }

    public function test_the_channel_only_looks_at_the_team_guard(): void
    {
        $broadcaster = app(BroadcastManager::class)->connection();
        $options = (new \ReflectionProperty(Broadcaster::class, 'channelOptions'))->getValue($broadcaster);

        $this->assertIsArray($options);
        $this->assertSame(['guards' => ['web']], $options[self::PATTERN] ?? null);
    }

    public function test_the_event_is_broadcast_on_the_authorised_channel(): void
    {
        $this->assertSame(
            str_replace('{orgId}', '42', self::PATTERN),
            ReceiptSubmitted::channelFor(42),
        );
        $this->assertArrayHasKey(self::PATTERN, $this->channels());
    }

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
