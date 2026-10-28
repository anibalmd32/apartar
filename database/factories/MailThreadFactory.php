<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MailThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailThread>
 */
class MailThreadFactory extends Factory
{
    /** @var class-string<MailThread> */
    protected $model = MailThread::class;

    /**
     * @return array<model-property<MailThread>, mixed>
     */
    public function definition(): array
    {
        return [
            'subject' => fake()->sentence(4),
            'counterpart' => fake()->safeEmail(),
            'snippet' => fake()->sentence(),
            'messages_count' => 0,
            'unread_count' => 0,
            'last_message_at' => now(),
        ];
    }
}
