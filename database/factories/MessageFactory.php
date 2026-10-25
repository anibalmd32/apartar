<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Chat\MessageSenderType;
use App\Models\Conversation;
use App\Models\Message;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<Message> */
    protected $model = Message::class;

    /**
     * @return array<model-property<Message>, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'sender_type' => MessageSenderType::Customer,
            'sender_id' => fn (array $attributes): int => (int) Conversation::query()->whereKey($attributes['conversation_id'])->value('customer_id'),
            'body' => fake()->sentence(),
            'idempotency_key' => null,
            'read_at' => null,
        ];
    }
}
