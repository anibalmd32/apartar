<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Customer;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<Conversation> */
    protected $model = Conversation::class;

    /**
     * @return array<model-property<Conversation>, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'last_message_at' => null,
            'customer_unread_count' => 0,
            'admin_unread_count' => 0,
        ];
    }
}
