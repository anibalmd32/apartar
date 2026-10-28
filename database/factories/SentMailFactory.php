<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Mail\MailStatus;
use App\Models\SentMail;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SentMail>
 */
class SentMailFactory extends Factory
{
    /** @var class-string<SentMail> */
    protected $model = SentMail::class;

    /**
     * @return array<model-property<SentMail>, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();
        $to = fake()->unique()->safeEmail();

        return [
            'uuid' => $uuid,
            'message_id' => $uuid.'@apartar.app',
            'from_address' => 'no-reply@apartar.app',
            'from_name' => 'Apartar',
            'to' => [$to],
            'cc' => [],
            'bcc' => [],
            'recipient_domain' => substr($to, strrpos($to, '@') + 1),
            'subject' => fake()->sentence(4),
            'html_body' => '<p>Hola</p>',
            'text_body' => 'Hola',
            'headers' => [],
            'category' => 'TeamInvitationNotification',
            'status' => MailStatus::Sent,
            'sent_at' => now(),
        ];
    }

    public function to(string $email): static
    {
        return $this->state(fn (): array => [
            'to' => [$email],
            'recipient_domain' => substr($email, strrpos($email, '@') + 1),
        ]);
    }
}
