<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Domain\Mail\MailStatus;
use App\Jobs\ProcessMailtrapEvents;
use App\Models\SentMail;
use App\Models\SentMailEvent;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class MailtrapSendingWebhookTest extends TestCase
{
    use ReusesMigratedDatabase;

    private const SECRET = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.mailtrap.sending_webhook_secret' => self::SECRET]);
    }

    public function test_a_signed_batch_is_queued_and_answered_at_once(): void
    {
        Queue::fake();
        $body = (string) json_encode(['events' => [$this->event('delivery', 'evt-1')]]);

        $this->post_($body, hash_hmac('sha256', $body, self::SECRET))->assertOk();

        Queue::assertPushed(ProcessMailtrapEvents::class, fn (ProcessMailtrapEvents $job): bool => $job->events[0]['event_id'] === 'evt-1' && $job->queue === 'default');
    }

    public function test_a_signed_batch_updates_the_sent_mail(): void
    {
        $mail = SentMail::factory()->create();
        $body = (string) json_encode(['events' => [
            $this->event('delivery', 'evt-1', $mail->uuid),
            $this->event('open', 'evt-2', $mail->uuid),
        ]]);

        $this->post_($body, hash_hmac('sha256', $body, self::SECRET))->assertOk();

        $mail->refresh();
        $this->assertSame(MailStatus::Opened, $mail->status);
        $this->assertSame('mt-message-1', $mail->mailtrap_message_id);
        $this->assertSame(2, SentMailEvent::query()->where('sent_mail_id', $mail->getKey())->count());
    }

    public function test_json_lines_are_accepted(): void
    {
        $mail = SentMail::factory()->create();
        $body = json_encode($this->event('delivery', 'evt-1', $mail->uuid))."\n".json_encode($this->event('open', 'evt-2', $mail->uuid))."\n";

        $this->post_($body, hash_hmac('sha256', $body, self::SECRET), 'application/jsonl')->assertOk();

        $this->assertSame(2, SentMailEvent::query()->count());
    }

    public function test_a_wrong_or_missing_signature_is_rejected_without_storing_anything(): void
    {
        $mail = SentMail::factory()->create();
        $body = (string) json_encode(['events' => [$this->event('spam', 'evt-1', $mail->uuid)]]);

        $this->post_($body, hash_hmac('sha256', $body, 'otro-secreto'))->assertUnauthorized();
        $this->post_($body, null)->assertUnauthorized();
        $this->post_($body.' ', hash_hmac('sha256', $body, self::SECRET))->assertUnauthorized();

        $this->assertSame(0, SentMailEvent::query()->count());
        $this->assertSame(MailStatus::Sent, $mail->refresh()->status);
    }

    public function test_without_a_configured_secret_nothing_gets_in(): void
    {
        config(['services.mailtrap.sending_webhook_secret' => null]);
        $body = (string) json_encode(['events' => [$this->event('delivery', 'evt-1')]]);

        $this->post_($body, hash_hmac('sha256', $body, ''))->assertUnauthorized();
    }

    public function test_a_signed_but_unreadable_body_is_a_bad_request(): void
    {
        $body = 'esto no es json';

        $this->post_($body, hash_hmac('sha256', $body, self::SECRET))->assertBadRequest();
    }

    public function test_the_same_batch_twice_counts_once(): void
    {
        $mail = SentMail::factory()->create();
        $body = (string) json_encode(['events' => [$this->event('open', 'evt-1', $mail->uuid)]]);
        $signature = hash_hmac('sha256', $body, self::SECRET);

        $this->post_($body, $signature)->assertOk();
        $this->post_($body, $signature)->assertOk();

        $this->assertSame(1, SentMailEvent::query()->count());
        $this->assertSame(1, $mail->refresh()->opens_count);
    }

    public function test_the_endpoint_is_rate_limited(): void
    {
        $this->assertContains('throttle:webhooks', app('router')->getRoutes()->getByName('api.mailtrap.sending')?->gatherMiddleware() ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function event(string $type, string $id, ?string $uuid = null): array
    {
        return array_filter([
            'event' => $type,
            'timestamp' => 1761652800,
            'sending_stream' => 'transactional',
            'category' => 'LayawayCodeNotification',
            'custom_variables' => $uuid === null ? null : ['apartar_mail_id' => $uuid],
            'message_id' => 'mt-message-1',
            'email' => 'cliente@gmail.com',
            'event_id' => $id,
            'sending_domain_name' => 'apartar.app',
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function post_(string $body, ?string $signature, string $type = 'application/json'): TestResponse
    {
        $server = ['CONTENT_TYPE' => $type];

        if ($signature !== null) {
            $server['HTTP_MAILTRAP_SIGNATURE'] = $signature;
        }

        return $this->call('POST', '/api/webhooks/mailtrap/envios', [], [], [], $server, $body);
    }
}
