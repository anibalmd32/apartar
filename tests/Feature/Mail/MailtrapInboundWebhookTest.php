<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Jobs\ImportInboundMessage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class MailtrapInboundWebhookTest extends TestCase
{
    use ReusesMigratedDatabase;

    private const SECRET = 'f0e1d2c3b4a5968778695a4b3c2d1e0f';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.mailtrap.inbound_webhook_secret' => self::SECRET, 'services.mailtrap.sending_webhook_secret' => 'otro']);
    }

    public function test_each_received_message_is_fetched_by_a_queued_job(): void
    {
        $body = (string) json_encode(['events' => [
            ['event' => 'inbound.message_received', 'event_id' => 'e1', 'timestamp' => 1761652800, 'inbox_id' => 15, 'message_id' => '1866872391602282496', 'sender' => 'a@example.com'],
            ['event' => 'inbound.message_received', 'event_id' => 'e2', 'inbox_id' => 15, 'message_id' => 1866872391602282497],
            ['event' => 'delivery', 'event_id' => 'e3', 'message_id' => 'x'],
            ['event' => 'inbound.message_received', 'event_id' => 'e4'],
        ]]);

        $this->post_($body, hash_hmac('sha256', $body, self::SECRET))->assertOk();

        Queue::assertPushed(ImportInboundMessage::class, 2);
        Queue::assertPushed(ImportInboundMessage::class, fn (ImportInboundMessage $job): bool => $job->inboxId === 15 && $job->messageId === '1866872391602282496' && $job->queue === 'high');
    }

    public function test_it_only_trusts_the_inbound_signing_secret(): void
    {
        $body = (string) json_encode(['events' => [['event' => 'inbound.message_received', 'inbox_id' => 15, 'message_id' => 'm']]]);

        $this->post_($body, hash_hmac('sha256', $body, 'otro'))->assertUnauthorized();
        $this->post_($body, null)->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function post_(string $body, ?string $signature): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($signature !== null) {
            $server['HTTP_MAILTRAP_SIGNATURE'] = $signature;
        }

        return $this->call('POST', '/api/webhooks/mailtrap/entrantes', [], [], [], $server, $body);
    }
}
