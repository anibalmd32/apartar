<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Actions\Chat\SendMessageAction;
use App\Domain\Chat\MessageSenderType;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class SendMessageActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MessageSent::class]);
        $this->organization = Organization::factory()->create();
        $this->customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::factory()->create(['full_name' => 'María González']));
        $this->withoutOrganization();
    }

    public function test_the_first_message_creates_the_thread_and_counters_follow_the_sender(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create(['name' => 'Ana del equipo']));
        $this->withoutOrganization();

        $this->send('¿Tienen el taladro?');
        $this->send('¿Y en rojo?');
        $this->send('Sí, en los dos colores.', MessageSenderType::Team, (int) $member->getKey());

        $conversation = $this->withinOrganization($this->organization, fn (): Conversation => Conversation::query()->sole());
        $this->assertSame(2, $conversation->admin_unread_count);
        $this->assertSame(1, $conversation->customer_unread_count);
        $this->assertNotNull($conversation->last_message_at);

        Event::assertDispatched(MessageSent::class, fn (MessageSent $event): bool => $event->senderName === 'Ana del equipo');
        Event::assertDispatched(MessageSent::class, fn (MessageSent $event): bool => $event->senderName === 'María González');
    }

    public function test_the_same_idempotency_key_does_not_duplicate(): void
    {
        $key = (string) Str::uuid();

        $first = $this->send('Hola', key: $key);
        $second = $this->send('Hola', key: $key);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, $this->withinOrganization($this->organization, fn (): int => Message::query()->count()));
    }

    public function test_empty_and_too_long_messages_are_refused(): void
    {
        foreach (['', '   ', str_repeat('a', Message::MAX_LENGTH + 1)] as $body) {
            try {
                $this->send($body);
                $this->fail('Aceptó un mensaje inválido.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('a', $this->send(str_repeat(' ', 3).'a'.str_repeat(' ', 3))->body);
    }

    public function test_a_record_of_another_organization_is_refused(): void
    {
        $neighbour = $this->neighbourOrganization();
        $foreign = $this->withinOrganization($neighbour, fn (): Customer => Customer::factory()->create());
        $this->withoutOrganization();

        $this->expectException(InvalidArgumentException::class);

        app(SendMessageAction::class)->handle($this->organization, $foreign, MessageSenderType::Customer, (int) $foreign->getKey(), 'Hola', (string) Str::uuid());
    }

    private function send(string $body, MessageSenderType $sender = MessageSenderType::Customer, ?int $senderId = null, ?string $key = null): Message
    {
        return app(SendMessageAction::class)->handle(
            $this->organization,
            $this->customer,
            $sender,
            $senderId ?? (int) $this->customer->getKey(),
            $body,
            $key ?? (string) Str::uuid(),
        );
    }
}
