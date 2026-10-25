<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Actions\Chat\MarkConversationReadAction;
use App\Domain\Chat\MessageSenderType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class MarkConversationReadActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_each_side_reads_the_others_messages_and_resets_its_counter(): void
    {
        $organization = Organization::factory()->create();

        $this->withinOrganization($organization, function (): void {
            $conversation = Conversation::factory()->create(['admin_unread_count' => 2, 'customer_unread_count' => 1]);
            Message::factory()->count(2)->create(['conversation_id' => $conversation->getKey()]);
            Message::factory()->create([
                'conversation_id' => $conversation->getKey(),
                'sender_type' => MessageSenderType::Team,
                'sender_id' => User::factory()->create()->getKey(),
            ]);

            app(MarkConversationReadAction::class)->handle($conversation, MessageSenderType::Team);

            $fresh = $conversation->fresh();
            $this->assertNotNull($fresh);
            $this->assertSame(0, $fresh->admin_unread_count);
            $this->assertSame(1, $fresh->customer_unread_count);
            $this->assertSame(0, Message::query()->where('sender_type', MessageSenderType::Customer)->whereNull('read_at')->count());
            $this->assertSame(1, Message::query()->where('sender_type', MessageSenderType::Team)->whereNull('read_at')->count());

            app(MarkConversationReadAction::class)->handle($conversation, MessageSenderType::Customer);
            app(MarkConversationReadAction::class)->handle($conversation, MessageSenderType::Customer);

            $this->assertSame(0, $conversation->fresh()?->customer_unread_count);
            $this->assertSame(0, Message::query()->whereNull('read_at')->count());
        });
    }
}
