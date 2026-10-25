<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Domain\Chat\MessageSenderType;
use App\Domain\Subscription\PlanAllowance;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class BackofficeChatTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MessageSent::class]);
        CarbonImmutable::setTestNow('2026-10-21 12:00:00');
        $this->organization = Organization::factory()->create();
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_unread_threads_come_first(): void
    {
        $this->onPlan(historyDays: null);
        $this->thread('Ana', '2026-10-21 11:00:00', unread: 0);
        $this->thread('Beto', '2026-10-20 09:00:00', unread: 2);
        $this->thread('Carla', '2026-10-21 10:00:00', unread: 0);

        $this->actingAs($this->member)->get(route('chat.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('threads.0.customerName', 'Beto')
            ->where('threads.1.customerName', 'Ana')
            ->where('threads.2.customerName', 'Carla'));
    }

    public function test_the_unread_filter_keeps_only_unread_threads(): void
    {
        $this->onPlan(historyDays: null);
        $this->thread('Ana', '2026-10-21 11:00:00', unread: 0);
        $this->thread('Beto', '2026-10-20 09:00:00', unread: 2);

        $this->actingAs($this->member)->get(route('chat.index', ['no_leidos' => 1]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('unreadOnly', true)
            ->has('threads', 1)
            ->where('threads.0.customerName', 'Beto'));
    }

    public function test_search_by_the_four_criteria(): void
    {
        $this->onPlan(historyDays: null);
        $this->thread('Ana Pérez', '2026-10-21 11:00:00', document: 'V11111111', phone: '04141111111', body: 'quiero el taladro');
        $this->thread('Beto Ruiz', '2026-10-21 10:00:00', document: 'V22222222', phone: '04242222222', body: 'una nevera');

        foreach (['Pérez' => 'Ana Pérez', '2222' => 'Beto Ruiz', '04141111111' => 'Ana Pérez', 'nevera' => 'Beto Ruiz'] as $query => $expected) {
            $this->actingAs($this->member)->get(route('chat.index', ['q' => $query]))->assertInertia(fn (AssertableInertia $page) => $page
                ->has('threads', 1)
                ->where('threads.0.customerName', $expected));
        }
    }

    public function test_the_open_thread_carries_only_this_business_context_and_is_marked_read(): void
    {
        $this->onPlan(historyDays: null);
        $layaway = $this->layawayIn($this->organization);
        $customerId = (int) $layaway->customer_id;
        $neighbour = $this->subscribe($this->neighbourOrganization());
        $this->layawayIn($neighbour);
        $conversation = $this->withinOrganization($this->organization, function () use ($customerId): Conversation {
            $conversation = Conversation::factory()->create(['customer_id' => $customerId, 'admin_unread_count' => 1, 'last_message_at' => now()]);
            Message::factory()->create(['conversation_id' => $conversation->getKey()]);

            return $conversation;
        });
        $this->withoutOrganization();

        $this->actingAs($this->member)->get(route('chat.show', ['conversation' => $conversation->getKey()]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('active.id', $conversation->getKey())
            ->where('active.context.activeLayaways', 1)
            ->where('active.context.profileUrl', route('customers.show', ['customer' => $customerId]))
            ->has('active.context.layaways', 1)
            ->where('active.context.layaways.0.code', $layaway->code)
            ->where('active.context.layaways.0.url', route('layaways.show', $layaway))
            ->has('active.messages', 1)
            ->has('active.messages.0.readAt'));

        $this->assertSame(0, (int) DB::table('conversations')->where('id', $conversation->getKey())->value('admin_unread_count'));
    }

    public function test_free_shows_thirty_days_without_deleting_and_pro_shows_everything(): void
    {
        $this->onPlan(historyDays: 30);
        $conversation = $this->thread('Ana', '2026-10-21 11:00:00', body: 'reciente');
        $this->withinOrganization($this->organization, fn () => Message::factory()->create([
            'conversation_id' => $conversation->getKey(),
            'body' => 'de hace dos meses',
            'created_at' => '2026-08-20 10:00:00',
        ]));
        $this->withoutOrganization();

        $this->actingAs($this->member)->get(route('chat.show', ['conversation' => $conversation->getKey()]))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('active.messages', 1)
            ->where('active.hiddenHistory', true)
            ->where('historyDays', 30));

        $this->assertSame(2, DB::table('messages')->count());

        $this->withinOrganization($this->organization, fn () => Subscription::query()->sole()->forceFill(['plan_id' => Plan::factory()->unlimited()->create(['features' => []])->getKey()])->save());
        $this->withoutOrganization();

        $this->actingAs($this->member)->get(route('chat.show', ['conversation' => $conversation->getKey()]))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('active.messages', 2)
            ->where('historyDays', null));
    }

    public function test_another_business_thread_is_404(): void
    {
        $this->onPlan(historyDays: null);
        $neighbour = $this->neighbourOrganization();
        $theirs = $this->withinOrganization($neighbour, fn (): Conversation => Conversation::factory()->create());
        $this->withoutOrganization();

        $this->actingAs($this->member)->get(route('chat.show', ['conversation' => $theirs->getKey()]))->assertNotFound();
    }

    public function test_the_team_replies(): void
    {
        $this->onPlan(historyDays: null);
        $conversation = $this->thread('Ana', '2026-10-21 11:00:00');

        $this->actingAs($this->member)
            ->postJson(route('chat.store', ['conversation' => $conversation->getKey()]), ['body' => 'Hola, Ana', 'idempotencyKey' => (string) \Illuminate\Support\Str::uuid()])
            ->assertCreated();

        $this->assertSame(MessageSenderType::Team->value, DB::table('messages')->orderByDesc('id')->value('sender_type'));
        $this->assertSame(1, (int) DB::table('conversations')->where('id', $conversation->getKey())->value('customer_unread_count'));
    }

    private function onPlan(?int $historyDays): void
    {
        $plan = Plan::factory()->unlimited()->create(['features' => $historyDays === null ? [] : [PlanAllowance::ChatHistoryDays->value => $historyDays]]);
        $this->withinOrganization($this->organization, fn () => Subscription::factory()->forPlan($plan)->create());
        $this->withoutOrganization();
    }

    private function thread(string $name, string $lastAt, int $unread = 0, ?string $document = null, ?string $phone = null, string $body = 'hola'): Conversation
    {
        $conversation = $this->withinOrganization($this->organization, function () use ($name, $lastAt, $unread, $document, $phone, $body): Conversation {
            $customer = Customer::factory()->create(array_filter([
                'full_name' => $name,
                'document_number' => $document,
                'phone' => $phone,
            ]));
            $conversation = Conversation::factory()->create(['customer_id' => $customer->getKey(), 'admin_unread_count' => $unread, 'last_message_at' => $lastAt]);
            Message::factory()->create(['conversation_id' => $conversation->getKey(), 'body' => $body, 'created_at' => $lastAt]);

            return $conversation;
        });
        $this->withoutOrganization();

        return $conversation;
    }
}
