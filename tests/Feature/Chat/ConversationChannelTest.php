<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Broadcasting\ConversationChannel;
use App\Domain\Chat\MessageSenderType;
use App\Domain\Tenancy\OrganizationStatus;
use App\Events\MessageSent;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ConversationChannelTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private Conversation $conversation;

    private Customer $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        [$this->conversation, $this->owner] = $this->withinOrganization($this->organization, function (): array {
            $conversation = Conversation::factory()->create();

            return [$conversation, Customer::query()->findOrFail($conversation->customer_id)];
        });
        $this->withoutOrganization();
    }

    public function test_the_owner_and_an_active_member_join(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();

        $this->assertTrue($this->join($this->owner));
        $this->assertTrue($this->join($member));
    }

    public function test_a_member_of_another_organization_is_refused(): void
    {
        $neighbour = $this->neighbourOrganization();
        $intruder = $this->withinOrganization($neighbour, fn (): User => User::factory()->create());
        $this->withoutOrganization();

        $this->assertFalse($this->join($intruder));
        $this->assertFalse($this->channel()->join($intruder, $neighbour->getKey(), $this->conversation->getKey()));
    }

    public function test_a_conversation_of_another_organization_under_ones_own_org_id_does_not_exist(): void
    {
        $neighbour = $this->neighbourOrganization();
        $theirs = $this->withinOrganization($neighbour, fn (): Conversation => Conversation::factory()->create());
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();

        $this->assertFalse($this->channel()->join($member, $this->organization->getKey(), $theirs->getKey()));
    }

    public function test_another_customer_of_the_same_business_is_refused(): void
    {
        $other = $this->withinOrganization($this->organization, fn (): Customer => Customer::factory()->create());
        $this->withoutOrganization();

        $this->assertFalse($this->join($other));
    }

    public function test_an_account_joins_only_through_its_linked_record(): void
    {
        $account = Account::factory()->create();

        $this->assertFalse($this->join($account));

        DB::table('customers')->where('id', $this->owner->getKey())->update(['account_id' => $account->getKey()]);

        $this->assertTrue($this->join($account));
    }

    public function test_disabled_members_and_closed_organizations_are_refused_but_suspended_ones_join(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $disabled = $this->withinOrganization($this->organization, fn (): User => User::factory()->create(['disabled_at' => now()]));
        $this->withoutOrganization();

        $this->assertFalse($this->join($disabled));

        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $this->assertTrue($this->join($member->fresh() ?? $member));

        $this->organization->forceFill(['status' => OrganizationStatus::Closed])->save();
        $this->assertFalse($this->join($member->fresh() ?? $member));
    }

    public function test_the_payload_carries_the_message_and_nothing_else(): void
    {
        $message = $this->withinOrganization($this->organization, fn (): Message => Message::factory()->create([
            'conversation_id' => $this->conversation->getKey(),
            'body' => 'Hola, ¿tienen el taladro?',
        ]));
        $event = MessageSent::of($message, 'María');

        $this->assertSame(['id', 'conversationId', 'sender', 'senderName', 'body', 'sentAt'], array_keys($event->broadcastWith()));
        $this->assertSame(MessageSenderType::Customer->value, $event->broadcastWith()['sender']);
        $this->assertSame([
            sprintf('private-organization.%d.conversation.%d', $this->organization->getKey(), $this->conversation->getKey()),
            sprintf('private-organization.%d.chat', $this->organization->getKey()),
        ], array_map(fn ($channel): string => $channel->name, $event->broadcastOn()));
    }

    public function test_a_rolled_back_message_is_not_broadcast(): void
    {
        Event::fake([MessageSent::class]);

        try {
            DB::transaction(function (): void {
                $message = $this->withinOrganization($this->organization, fn (): Message => Message::factory()->create(['conversation_id' => $this->conversation->getKey()]));
                event(MessageSent::of($message, 'María'));

                throw new RuntimeException('se deshace');
            });
        } catch (RuntimeException) {
        }

        Event::assertNotDispatched(MessageSent::class);
    }

    private function join(User|Customer|Account $who): bool
    {
        return $this->channel()->join($who, $this->organization->getKey(), $this->conversation->getKey());
    }

    private function channel(): ConversationChannel
    {
        return app(ConversationChannel::class);
    }
}
