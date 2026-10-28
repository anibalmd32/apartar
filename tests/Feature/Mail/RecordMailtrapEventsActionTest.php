<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Actions\Mail\RecordMailtrapEventsAction;
use App\Domain\Mail\MailEventType;
use App\Domain\Mail\MailStatus;
use App\Models\SentMail;
use App\Models\SentMailEvent;
use Carbon\CarbonImmutable;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class RecordMailtrapEventsActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_it_links_events_by_our_id_and_then_by_the_mailtrap_message_id(): void
    {
        $mail = SentMail::factory()->create();

        $recorded = $this->action()->handle([
            $this->event('delivery', 'e1', ['custom_variables' => ['apartar_mail_id' => strtoupper($mail->uuid)], 'timestamp' => 1761652800]),
            $this->event('open', 'e2', ['timestamp' => 1761656400, 'ip' => '127.0.0.1', 'user_agent' => 'Mozilla/5.0']),
        ]);

        $this->assertSame(2, $recorded);
        $mail->refresh();
        $this->assertSame('mt-1', $mail->mailtrap_message_id);
        $this->assertSame(MailStatus::Opened, $mail->status);
        $this->assertTrue(CarbonImmutable::createFromTimestamp(1761652800)->equalTo($mail->delivered_at));
        $this->assertTrue(CarbonImmutable::createFromTimestamp(1761656400)->equalTo($mail->first_opened_at));
        $this->assertTrue(CarbonImmutable::createFromTimestamp(1761656400)->equalTo($mail->last_event_at));

        $open = SentMailEvent::query()->where('event_id', 'e2')->sole();
        $this->assertSame($mail->getKey(), $open->sent_mail_id);
        $this->assertSame(MailEventType::Open, $open->type);
        $this->assertSame('127.0.0.1', $open->ip);
        $this->assertSame('Mozilla/5.0', $open->user_agent);
        $this->assertSame('open', $open->payload['event']);
    }

    public function test_it_stores_bounce_and_click_details_and_counts_repeated_opens_and_clicks(): void
    {
        $mail = SentMail::factory()->create(['mailtrap_message_id' => 'mt-1']);

        $this->action()->handle([
            $this->event('open', 'e1'),
            $this->event('open', 'e2'),
            $this->event('click', 'e3', ['url' => 'https://apartar.app/precios']),
            $this->event('click', 'e4', ['url' => 'https://apartar.app/precios']),
            $this->event('soft bounce', 'e5', ['response' => '4.7.1 Try again', 'response_code' => 451, 'bounce_category' => 'greylisting']),
        ]);

        $mail->refresh();
        $this->assertSame(2, $mail->opens_count);
        $this->assertSame(2, $mail->clicks_count);
        $this->assertSame(MailStatus::Clicked, $mail->status);

        $bounce = SentMailEvent::query()->where('event_id', 'e5')->sole();
        $this->assertSame(MailEventType::SoftBounce, $bounce->type);
        $this->assertSame('4.7.1 Try again', $bounce->reason);
        $this->assertSame(451, $bounce->response_code);
        $this->assertSame('greylisting', $bounce->bounce_category);
        $this->assertSame('https://apartar.app/precios', SentMailEvent::query()->where('event_id', 'e3')->value('url'));
    }

    public function test_a_late_delivery_does_not_undo_a_bounce_or_an_open(): void
    {
        $bounced = SentMail::factory()->create(['mailtrap_message_id' => 'mt-b']);
        $opened = SentMail::factory()->create(['mailtrap_message_id' => 'mt-o']);

        $this->action()->handle([
            $this->event('bounce', 'b1', ['message_id' => 'mt-b']),
            $this->event('delivery', 'b2', ['message_id' => 'mt-b']),
            $this->event('open', 'o1', ['message_id' => 'mt-o', 'timestamp' => 1761656400]),
            $this->event('delivery', 'o2', ['message_id' => 'mt-o', 'timestamp' => 1761652800]),
        ]);

        $this->assertSame(MailStatus::Bounced, $bounced->refresh()->status);
        $this->assertSame(MailStatus::Opened, $opened->refresh()->status);
        $this->assertTrue(CarbonImmutable::createFromTimestamp(1761656400)->equalTo($opened->last_event_at));
    }

    public function test_it_is_idempotent_by_event_id(): void
    {
        $mail = SentMail::factory()->create(['mailtrap_message_id' => 'mt-1']);

        $this->assertSame(1, $this->action()->handle([$this->event('open', 'e1')]));
        $this->assertSame(0, $this->action()->handle([$this->event('open', 'e1'), $this->event('open', 'e1')]));

        $this->assertSame(1, SentMailEvent::query()->count());
        $this->assertSame(1, $mail->refresh()->opens_count);
    }

    public function test_it_keeps_orphan_events_and_skips_what_is_not_a_sending_event(): void
    {
        $recorded = $this->action()->handle([
            $this->event('delivery', 'e1', ['message_id' => 'mt-desconocido']),
            ['event' => 'activity_log.user.login', 'timestamp' => 1761652800],
            ['event' => 'open'],
        ]);

        $this->assertSame(2, $recorded);
        $this->assertNull(SentMailEvent::query()->where('event_id', 'e1')->value('sent_mail_id'));
        $this->assertSame(1, SentMailEvent::query()->where('event_id', 'like', 'h:%')->count());
    }

    private function action(): RecordMailtrapEventsAction
    {
        return app(RecordMailtrapEventsAction::class);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function event(string $type, string $id, array $extra = []): array
    {
        return [
            'event' => $type,
            'event_id' => $id,
            'message_id' => 'mt-1',
            'email' => 'cliente@gmail.com',
            'timestamp' => 1761652800,
            'sending_stream' => 'transactional',
            'sending_domain_name' => 'apartar.app',
            ...$extra,
        ];
    }
}
