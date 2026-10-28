<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Domain\Mail\MailCategory;
use App\Domain\Mail\MailStatus;
use App\Models\InboundMail;
use App\Models\MailThread;
use App\Models\SentMail;
use App\Models\SentMailEvent;
use App\Queries\Platform\MailMetricsQuery;
use Carbon\CarbonImmutable;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class MailMetricsQueryTest extends TestCase
{
    use ReusesMigratedDatabase;

    private int $events = 0;

    public function test_it_computes_totals_rates_and_breakdowns_from_our_tables(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00'));
        $this->seedScenario();

        $metrics = app(MailMetricsQuery::class)->compute(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-28'));

        $this->assertSame([
            'sent' => 7, 'pending' => 1, 'failed' => 1, 'manual' => 1,
            'delivered' => 4, 'openedUnique' => 2, 'openedTotal' => 3, 'clickedUnique' => 1, 'clickedTotal' => 1,
            'bounced' => 1, 'softBounced' => 1, 'spam' => 1, 'unsubscribed' => 1, 'rejected' => 0, 'inbound' => 2,
        ], $metrics['totals']);

        $this->assertSame([
            'delivery' => 0.6667, 'open' => 0.5, 'click' => 0.25, 'clickToOpen' => 0.5,
            'bounce' => 0.1667, 'spam' => 0.25, 'unsubscribe' => 0.25, 'failure' => 0.1667,
        ], $metrics['rates']);

        $this->assertSame(45, $metrics['avgMinutesToOpen']);
        $this->assertCount(28, $metrics['daily']);
        $this->assertSame(['date' => '2026-10-27', 'sent' => 3, 'delivered' => 2, 'opened' => 2, 'clicked' => 1, 'bounced' => 0], $metrics['daily'][26]);
        $this->assertSame(0, $metrics['daily'][0]['sent']);

        $categories = $this->keyed($metrics['byCategory'], 'category');
        $this->assertSame(['sent' => 2, 'delivered' => 2, 'opened' => 2, 'clicked' => 1, 'bounced' => 0], array_intersect_key($categories['LayawayCodeNotification'], array_flip(['sent', 'delivered', 'opened', 'clicked', 'bounced'])));
        $this->assertSame(1, $categories['TeamInvitationNotification']['bounced']);
        $this->assertSame('Escrito por el equipo', $categories[MailCategory::MANUAL]['label']);

        $providers = $this->keyed($metrics['byProvider'], 'provider');
        $this->assertSame(['provider' => 'Gmail', 'sent' => 2, 'delivered' => 2, 'opened' => 1, 'clicked' => 1, 'bounced' => 0], $providers['Gmail']);
        $this->assertSame(2, $providers['Yahoo']['sent']);
        $this->assertSame(1, $providers['empresa.com.ve']['bounced']);
        $this->assertSame(1, $providers['Outlook']['opened']);

        $this->assertSame([['url' => 'https://apartar.app/precios', 'clicks' => 1, 'mails' => 1]], $metrics['topLinks']);
        $this->assertEqualsCanonicalizing([
            ['reason' => 'bad_mailbox', 'hard' => true, 'count' => 1],
            ['reason' => 'greylisting', 'hard' => false, 'count' => 1],
        ], $metrics['bounceReasons']);
    }

    public function test_an_empty_period_has_no_rates_instead_of_dividing_by_zero(): void
    {
        $metrics = app(MailMetricsQuery::class)->compute(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-07'));

        $this->assertSame(0, $metrics['totals']['sent']);
        $this->assertNull($metrics['rates']['delivery']);
        $this->assertNull($metrics['avgMinutesToOpen']);
        $this->assertCount(7, $metrics['daily']);
        $this->assertSame([], $metrics['topLinks']);
    }

    public function test_the_dashboard_summary_covers_the_last_thirty_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00'));
        $this->seedScenario();

        $summary = app(MailMetricsQuery::class)->summary();

        $this->assertSame(['sent' => 7, 'deliveryRate' => 0.6667, 'openRate' => 0.5, 'bounceRate' => 0.1667, 'unreadThreads' => 1], $summary);
    }

    private function seedScenario(): void
    {
        $a = $this->mail('ana@gmail.com', 'LayawayCodeNotification', '2026-10-27 10:00', ['first_opened_at' => '2026-10-27 10:30']);
        $this->event($a, 'delivery');
        $this->event($a, 'open');
        $this->event($a, 'open');
        $this->event($a, 'click', ['url' => 'https://apartar.app/precios']);

        $b = $this->mail('beto@hotmail.com', 'LayawayCodeNotification', '2026-10-27 10:00', ['first_opened_at' => '2026-10-27 11:00']);
        $this->event($b, 'delivery');
        $this->event($b, 'open');

        $c = $this->mail('carla@empresa.com.ve', 'TeamInvitationNotification', '2026-10-26 09:00');
        $this->event($c, 'bounce', ['bounce_category' => 'bad_mailbox']);

        $d = $this->mail('dani@gmail.com', 'TeamInvitationNotification', '2026-10-26 09:00');
        $this->event($d, 'delivery');
        $this->event($d, 'spam');

        $this->mail('eva@yahoo.com', 'ExportFinishedNotification', '2026-10-28 11:58', ['status' => MailStatus::Sending, 'sent_at' => null]);
        $this->mail('fer@yahoo.com', 'ExportFinishedNotification', '2026-10-27 08:00', ['status' => MailStatus::Sending, 'sent_at' => null]);

        $g = $this->mail('rosa@icloud.com', MailCategory::MANUAL, '2026-10-25 15:00');
        $this->event($g, 'delivery');
        $this->event($g, 'soft_bounce', ['bounce_category' => 'greylisting']);
        $this->event($g, 'unsubscribe');

        $old = $this->mail('viejo@gmail.com', 'LayawayCodeNotification', '2026-09-01 10:00');
        $this->event($old, 'delivery');

        $thread = MailThread::factory()->create(['unread_count' => 1]);
        InboundMail::factory()->count(2)->create(['mail_thread_id' => $thread->getKey(), 'received_at' => '2026-10-20 10:00']);
        InboundMail::factory()->create(['mail_thread_id' => $thread->getKey(), 'received_at' => '2026-08-20 10:00']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function mail(string $to, string $category, string $createdAt, array $attributes = []): SentMail
    {
        return SentMail::factory()->to($to)->create([
            'category' => $category,
            'created_at' => $createdAt,
            'sent_at' => $createdAt,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(SentMail $mail, string $type, array $attributes = []): void
    {
        (new SentMailEvent)->forceFill([
            'event_id' => 'evt-'.(++$this->events),
            'sent_mail_id' => $mail->getKey(),
            'type' => $type,
            'occurred_at' => $mail->created_at,
            'payload' => [],
            'created_at' => now(),
            ...$attributes,
        ])->save();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function keyed(mixed $rows, string $key): array
    {
        $keyed = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row[$key] ?? null)) {
                $keyed[$row[$key]] = $row;
            }
        }

        return $keyed;
    }
}
