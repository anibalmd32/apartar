<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ChannelAuthorizationTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $member;

    private Customer $customer;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'clave',
            'broadcasting.connections.reverb.secret' => 'secreto',
            'broadcasting.connections.reverb.app_id' => 'apartar',
        ]);
        Broadcast::purge('reverb');
        require base_path('routes/channels.php');

        $this->organization = $this->subscribe(Organization::factory()->create());
        $layaway = $this->layawayIn($this->organization);
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->findOrFail($layaway->customer_id));
        $this->conversation = $this->withinOrganization($this->organization, fn (): Conversation => Conversation::query()->create(['customer_id' => $this->customer->getKey()]));
        $this->withoutOrganization();
    }

    public function test_the_team_member_is_authorized_on_the_business_and_the_conversation_channels(): void
    {
        foreach ([MessageSent::chatChannel((int) $this->organization->getKey()), MessageSent::conversationChannel((int) $this->organization->getKey(), (int) $this->conversation->getKey())] as $channel) {
            $this->actingAs($this->member)
                ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-'.$channel])
                ->assertOk()
                ->assertJsonStructure(['auth']);
        }
    }

    public function test_the_customer_is_authorized_on_its_conversation_channel(): void
    {
        $this->actingAs($this->customer, 'customer')
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-'.MessageSent::conversationChannel((int) $this->organization->getKey(), (int) $this->conversation->getKey())])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }
}
