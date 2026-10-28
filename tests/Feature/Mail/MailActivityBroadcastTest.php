<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Actions\Mail\ImportInboundMessageAction;
use App\Actions\Mail\RecordMailtrapEventsAction;
use App\Actions\Mail\SendOwnerMailAction;
use App\Domain\Mail\ComposeMode;
use App\Domain\Mail\MailActivityKind;
use App\Domain\Mail\MailStatus;
use App\Events\MailActivity;
use App\Models\InboundMail;
use App\Models\SentMail;
use App\Models\SentMailEvent;
use App\Notifications\LayawayCodeNotification;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class MailActivityBroadcastTest extends TestCase
{
    use ActsAsPlatformOperator, ReusesMigratedDatabase;

    private const API = 'https://mailtrap.io/api/inbound/inboxes/15/messages/';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.mailtrap.api_token' => 'token-de-prueba', 'services.mailtrap.inbox_id' => null]);
    }

    public function test_recording_an_outgoing_mail_announces_its_uuid_only(): void
    {
        Event::fake([MailActivity::class]);

        $this->notifyCustomer();

        $mail = SentMail::query()->sole();
        $events = $this->announced(MailActivityKind::Sent);

        $this->assertCount(2, $events);
        $this->assertSame([[$mail->uuid]], array_values(array_unique(array_map(fn (MailActivity $event): array => $event->mails, $events), SORT_REGULAR)));
        $this->assertPayloadIsBare($events[0], ['cliente@gmail.com', 'AP-7K3M9QRT', $mail->subject]);
    }

    public function test_an_owner_mail_announces_its_conversation(): void
    {
        Event::fake([MailActivity::class]);

        $sent = app(SendOwnerMailAction::class)->handle($this->operator(), ComposeMode::New, null, ['ana@empresa.com'], [], 'Precio', '<p>Hola</p>');

        $last = $this->announced(MailActivityKind::Sent);
        $this->assertSame([$sent->uuid], end($last)->mails);
        $this->assertSame([(int) $sent->mail_thread_id], end($last)->threads);
    }

    public function test_a_mailtrap_batch_announces_the_mails_it_touched_once(): void
    {
        Event::fake([MailActivity::class]);
        $mail = SentMail::factory()->create(['mailtrap_message_id' => 'mt-1']);

        app(RecordMailtrapEventsAction::class)->handle([$this->event('delivery', 'e1'), $this->event('open', 'e2')]);

        $events = $this->announced(MailActivityKind::Event);
        $this->assertCount(1, $events);
        $this->assertSame([$mail->uuid], $events[0]->mails);
        $this->assertPayloadIsBare($events[0], ['cliente@gmail.com']);
    }

    public function test_a_repeated_mailtrap_batch_announces_nothing(): void
    {
        SentMail::factory()->create(['mailtrap_message_id' => 'mt-1']);
        app(RecordMailtrapEventsAction::class)->handle([$this->event('delivery', 'e1')]);
        Event::fake([MailActivity::class]);

        app(RecordMailtrapEventsAction::class)->handle([$this->event('delivery', 'e1')]);

        Event::assertNotDispatched(MailActivity::class);
    }

    public function test_an_inbound_mail_announces_its_conversation(): void
    {
        Event::fake([MailActivity::class]);
        Http::fake([self::API.'msg-1' => Http::response($this->message('msg-1'))]);

        $mail = app(ImportInboundMessageAction::class)->handle(15, 'msg-1');

        $this->assertInstanceOf(InboundMail::class, $mail);
        $events = $this->announced(MailActivityKind::Inbound);
        $this->assertCount(1, $events);
        $this->assertSame([], $events[0]->mails);
        $this->assertSame([(int) $mail->mail_thread_id], $events[0]->threads);
        $this->assertPayloadIsBare($events[0], ['demo.eth@gmail.com', 'Pregunta por el plan', 'Hola']);
    }

    public function test_a_broken_broadcaster_breaks_neither_sending_nor_webhooks_nor_inbound(): void
    {
        $this->mock(BroadcastFactory::class, function (MockInterface $mock): void {
            $mock->shouldReceive('queue')->atLeast()->times(4)->andThrow(new RuntimeException('Reverb caído'));
        });
        Http::fake([self::API.'msg-1' => Http::response($this->message('msg-1'))]);

        $this->notifyCustomer();
        $mail = SentMail::query()->sole();
        $this->assertSame(MailStatus::Sent, $mail->status);

        $recorded = app(RecordMailtrapEventsAction::class)->handle([$this->event('delivery', 'e1', ['message_id' => 'mt-9', 'custom_variables' => ['apartar_mail_id' => $mail->uuid]])]);
        $this->assertSame(1, $recorded);
        $this->assertSame(MailStatus::Delivered, $mail->refresh()->status);
        $this->assertSame(1, SentMailEvent::query()->count());

        $this->assertInstanceOf(InboundMail::class, app(ImportInboundMessageAction::class)->handle(15, 'msg-1'));
    }

    private function notifyCustomer(): void
    {
        Notification::route('mail', 'cliente@gmail.com')->notify(new LayawayCodeNotification('AP-7K3M9QRT', 'Moda Valentina', 'Vestido lino', 'https://apartar.app/mi-cuenta'));
    }

    /**
     * @return list<MailActivity>
     */
    private function announced(MailActivityKind $kind): array
    {
        return Event::dispatched(MailActivity::class, fn (MailActivity $event): bool => $event->kind === $kind)
            ->map(fn (array $arguments): MailActivity => $arguments[0])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $secrets
     */
    private function assertPayloadIsBare(MailActivity $event, array $secrets): void
    {
        $payload = $event->broadcastWith();
        $json = (string) json_encode($payload);

        $this->assertSame(['kind', 'mails', 'threads'], array_keys($payload));
        $this->assertSame(['private-'.MailActivity::CHANNEL], array_map(fn ($channel): string => $channel->name, $event->broadcastOn()));

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function event(string $type, string $id, array $extra = []): array
    {
        return ['event' => $type, 'event_id' => $id, 'message_id' => 'mt-1', 'email' => 'cliente@gmail.com', 'timestamp' => 1761652800, ...$extra];
    }

    /**
     * @return array<string, mixed>
     */
    private function message(string $id): array
    {
        return [
            'id' => $id,
            'inbox_id' => 15,
            'from' => 'Ivan Djuric <demo.eth@gmail.com>',
            'to' => ['ventas@apartar.app'],
            'subject' => 'Pregunta por el plan',
            'rfc_message_id' => '<2C613DD4@gmail.com>',
            'received_at' => '2026-10-28T08:17:31.878Z',
            'thread_id' => 'thr-1',
            'html_body' => '<p>Hola</p>',
            'text_body' => 'Hola',
            'attachments' => [],
        ];
    }
}
