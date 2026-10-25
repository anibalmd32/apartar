<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Actions\Chat\SendMessageAction;
use App\Console\Commands\RecountConversations;
use App\Domain\Chat\MessageSenderType;
use App\Events\MessageSent;
use App\Events\UserTyping;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class UnreadCountersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Customer $customer;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MessageSent::class, UserTyping::class]);
        $this->organization = Organization::factory()->create();
        [$this->customer, $this->member] = $this->withinOrganization($this->organization, function (): array {
            Subscription::factory()->forPlan(Plan::factory()->unlimited()->create(['features' => []]))->create();

            return [Customer::factory()->create(), User::factory()->create()];
        });
        $this->withoutOrganization();
    }

    public function test_counters_after_ten_messages_both_ways_and_idempotent_marking(): void
    {
        foreach (range(1, 10) as $index) {
            $this->send($index % 2 === 0 ? MessageSenderType::Team : MessageSenderType::Customer);
        }

        $conversation = $this->conversation();
        $this->assertSame([5, 5], [$conversation->admin_unread_count, $conversation->customer_unread_count]);

        $this->withinOrganization($this->organization, function () use ($conversation): void {
            $this->assertSame(5, $conversation->markReadBy(MessageSenderType::Team));
            $this->assertSame(0, $conversation->markReadBy(MessageSenderType::Team));
        });

        $this->assertSame([0, 5], [$this->conversation()->admin_unread_count, $this->conversation()->customer_unread_count]);
    }

    public function test_the_free_window_does_not_alter_the_count(): void
    {
        $this->send(MessageSenderType::Customer);
        $this->withinOrganization($this->organization, fn () => Message::query()->update(['created_at' => CarbonImmutable::now()->subDays(60)]));
        $this->send(MessageSenderType::Customer);

        $this->assertSame(2, $this->conversation()->admin_unread_count);

        $conversation = $this->conversation();
        $this->withinOrganization($this->organization, fn () => $conversation->markReadBy(MessageSenderType::Team));
        $this->assertSame(0, (int) DB::table('messages')->whereNull('read_at')->count());
    }

    public function test_recount_fixes_drift_across_organizations(): void
    {
        $this->send(MessageSenderType::Customer);
        $other = Organization::factory()->create();
        $theirs = $this->withinOrganization($other, function (): Conversation {
            $conversation = Conversation::factory()->create();
            Message::factory()->count(2)->create(['conversation_id' => $conversation->getKey()]);

            return $conversation;
        });
        $this->withoutOrganization();

        DB::table('conversations')->update(['admin_unread_count' => 9, 'customer_unread_count' => 4]);

        $this->artisan('chat:recount')->assertSuccessful();

        $this->assertSame([1, 0], [(int) DB::table('conversations')->where('id', $this->conversation()->getKey())->value('admin_unread_count'), (int) DB::table('conversations')->where('id', $this->conversation()->getKey())->value('customer_unread_count')]);
        $this->assertSame(2, (int) DB::table('conversations')->where('id', $theirs->getKey())->value('admin_unread_count'));
        $this->assertSame(2, Cache::get(RecountConversations::LAST_RUN_KEY)['drifts'] ?? null);

        $this->artisan('chat:recount')->assertSuccessful();
        $this->assertSame(0, Cache::get(RecountConversations::LAST_RUN_KEY)['drifts'] ?? null);
    }

    public function test_the_backoffice_badge_counts_threads_not_messages(): void
    {
        $this->send(MessageSenderType::Customer);
        $this->send(MessageSenderType::Customer);
        $this->send(MessageSenderType::Customer);

        $this->actingAs($this->member)->get(route('chat.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('teamMember.unreadChats', 1));
    }

    public function test_the_portal_badge_adds_up_several_businesses(): void
    {
        $account = Account::factory()->create();
        DB::table('customers')->where('id', $this->customer->getKey())->update(['account_id' => $account->getKey()]);
        $this->send(MessageSenderType::Team);

        $other = Organization::factory()->create();
        $this->withinOrganization($other, function () use ($account): void {
            $customer = Customer::factory()->create(['account_id' => $account->getKey()]);
            Conversation::factory()->create(['customer_id' => $customer->getKey(), 'customer_unread_count' => 3]);
        });
        $this->withoutOrganization();

        $this->actingAs($account, 'account')->get(route('portal.chat.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('auth.portal.unreadChats', 2));
    }

    public function test_typing_is_broadcast_on_the_thread_channel_and_not_stored(): void
    {
        $this->send(MessageSenderType::Customer);
        $before = DB::table('messages')->count();

        $this->actingAs($this->member)->postJson(route('chat.typing', ['conversation' => $this->conversation()->getKey()]))->assertStatus(202);

        Event::assertDispatched(UserTyping::class, fn (UserTyping $event): bool => $event->broadcastOn()[0]->name === 'private-'.MessageSent::conversationChannel((int) $this->organization->getKey(), (int) $this->conversation()->getKey())
            && $event->broadcastWith()['sender'] === 'team');
        $this->assertSame($before, DB::table('messages')->count());
    }

    private function send(MessageSenderType $sender): void
    {
        app(SendMessageAction::class)->handle(
            $this->organization,
            $this->customer,
            $sender,
            $sender === MessageSenderType::Team ? (int) $this->member->getKey() : (int) $this->customer->getKey(),
            'Mensaje '.Str::random(4),
            (string) Str::uuid(),
        );
        $this->withoutOrganization();
    }

    private function conversation(): Conversation
    {
        $conversation = $this->withinOrganization($this->organization, fn (): Conversation => Conversation::query()->where('customer_id', $this->customer->getKey())->sole());
        $this->withoutOrganization();

        return $conversation;
    }
}
