<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InboundMail;
use App\Models\MailThread;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InboundMail>
 */
class InboundMailFactory extends Factory
{
    /** @var class-string<InboundMail> */
    protected $model = InboundMail::class;

    /**
     * @return array<model-property<InboundMail>, mixed>
     */
    public function definition(): array
    {
        return [
            'mailtrap_message_id' => (string) fake()->unique()->numerify('18668723916######'),
            'mailtrap_inbox_id' => 15,
            'mail_thread_id' => MailThread::factory(),
            'from_address' => fake()->safeEmail(),
            'from_name' => fake()->name(),
            'to' => ['info@apartar.app'],
            'cc' => [],
            'subject' => fake()->sentence(4),
            'message_id' => Str::uuid().'@mail.example.com',
            'references' => [],
            'headers' => [],
            'html_body' => '<p>Hola, tengo una pregunta.</p>',
            'text_body' => 'Hola, tengo una pregunta.',
            'payload' => [],
            'received_at' => now(),
        ];
    }
}
