<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Mail\MailCategory;
use App\Domain\Mail\MailEventType;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\RecipientProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class MailMetricsQuery
{
    public const TOP_LINKS = 10;

    public const TOP_PROVIDERS = 8;

    private const BREAKDOWN_TYPES = [MailEventType::Delivery, MailEventType::Open, MailEventType::Click, MailEventType::Bounce];

    /**
     * @return array<string, mixed>
     */
    public function compute(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $range = [$from->startOfDay(), $to->endOfDay()];
        $mails = $this->mailTotals($range);
        $events = $this->eventTotals($range);
        $unique = fn (MailEventType $type): int => $events[$type->value]['mails'] ?? 0;
        $total = fn (MailEventType $type): int => $events[$type->value]['total'] ?? 0;
        $settled = max(0, $mails['sent'] - $mails['pending']);
        $delivered = $unique(MailEventType::Delivery);

        $totals = [
            'sent' => $mails['sent'],
            'pending' => $mails['pending'],
            'failed' => $mails['failed'],
            'manual' => $mails['manual'],
            'delivered' => $delivered,
            'openedUnique' => $unique(MailEventType::Open),
            'openedTotal' => $total(MailEventType::Open),
            'clickedUnique' => $unique(MailEventType::Click),
            'clickedTotal' => $total(MailEventType::Click),
            'bounced' => $unique(MailEventType::Bounce),
            'softBounced' => $unique(MailEventType::SoftBounce),
            'spam' => $unique(MailEventType::Spam),
            'unsubscribed' => $unique(MailEventType::Unsubscribe),
            'rejected' => $unique(MailEventType::Reject) + $unique(MailEventType::Suspension),
            'inbound' => DB::table('inbound_mails')->whereBetween('received_at', $range)->count(),
        ];

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $totals,
            'rates' => [
                'delivery' => self::rate($delivered, $settled),
                'open' => self::rate($totals['openedUnique'], $delivered),
                'click' => self::rate($totals['clickedUnique'], $delivered),
                'clickToOpen' => self::rate($totals['clickedUnique'], $totals['openedUnique']),
                'bounce' => self::rate($totals['bounced'], $settled),
                'spam' => self::rate($totals['spam'], $delivered),
                'unsubscribe' => self::rate($totals['unsubscribed'], $delivered),
                'failure' => self::rate($mails['failed'] + $totals['rejected'], $settled),
            ],
            'avgMinutesToOpen' => $mails['avgMinutesToOpen'],
            'daily' => $this->daily($from, $to, $range),
            'byCategory' => $this->byCategory($range),
            'byProvider' => $this->byProvider($range),
            'topLinks' => $this->topLinks($range),
            'bounceReasons' => $this->bounceReasons($range),
        ];
    }

    /**
     * @return array{sent: int, deliveryRate: float|null, openRate: float|null, bounceRate: float|null, unreadThreads: int}
     */
    public function summary(int $days = 30): array
    {
        $today = CarbonImmutable::today();
        $metrics = $this->compute($today->subDays($days - 1), $today);

        return [
            'sent' => $metrics['totals']['sent'],
            'deliveryRate' => $metrics['rates']['delivery'],
            'openRate' => $metrics['rates']['open'],
            'bounceRate' => $metrics['rates']['bounce'],
            'unreadThreads' => DB::table('mail_threads')->where('unread_count', '>', 0)->count(),
        ];
    }

    public static function rate(int $part, int $whole): ?float
    {
        return $whole <= 0 ? null : round($part / $whole, 4);
    }

    /**
     * @param  array{CarbonImmutable, CarbonImmutable}  $range
     * @return array{sent: int, pending: int, failed: int, manual: int, avgMinutesToOpen: int|null}
     */
    private function mailTotals(array $range): array
    {
        $cutoff = CarbonImmutable::now()->subMinutes(MailStatus::PENDING_TIMEOUT_MINUTES);
        $row = DB::table('sent_mails')
            ->whereBetween('created_at', $range)
            ->selectRaw('COUNT(*) AS sent')
            ->selectRaw('SUM(status = ? AND created_at >= ?) AS pending', [MailStatus::Sending->value, $cutoff])
            ->selectRaw('SUM(status = ? OR (status = ? AND created_at < ?)) AS failed', [MailStatus::Failed->value, MailStatus::Sending->value, $cutoff])
            ->selectRaw('SUM(category = ?) AS manual', [MailCategory::MANUAL])
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, COALESCE(sent_at, created_at), first_opened_at)) AS seconds_to_open')
            ->first();

        return [
            'sent' => (int) ($row->sent ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'manual' => (int) ($row->manual ?? 0),
            'avgMinutesToOpen' => $row?->seconds_to_open === null ? null : (int) round(max(0, (float) $row->seconds_to_open) / 60),
        ];
    }

    /**
     * @param  array{CarbonImmutable, CarbonImmutable}  $range
     * @return array<string, array{total: int, mails: int}>
     */
    private function eventTotals(array $range): array
    {
        $totals = [];

        foreach ($this->events($range)->groupBy('e.type')->selectRaw('e.type AS type, COUNT(*) AS total, COUNT(DISTINCT e.sent_mail_id) AS mails')->get() as $row) {
            $totals[(string) $row->type] = ['total' => (int) $row->total, 'mails' => (int) $row->mails];
        }

        return $totals;
    }

    /**
     * @param  array{CarbonImmutable, CarbonImmutable}  $range
     */
    private function events(array $range): Builder
    {
        return DB::table('sent_mail_events AS e')
            ->join('sent_mails AS m', 'm.id', '=', 'e.sent_mail_id')
            ->whereBetween('m.created_at', $range);
    }

    /**
     * @param  array{CarbonImmutable, CarbonImmutable}  $range
     * @return array<string, array<string, int>>
     */
    private function grouped(string $expression, array $range): array
    {
        $groups = [];

        foreach (DB::table('sent_mails AS m')->whereBetween('m.created_at', $range)->groupByRaw($expression)->selectRaw($expression.' AS k, COUNT(*) AS sent')->get() as $row) {
            $groups[(string) $row->k] = ['sent' => (int) $row->sent];
        }

        $events = $this->events($range)
            ->whereIn('e.type', array_map(fn (MailEventType $type): string => $type->value, self::BREAKDOWN_TYPES))
            ->groupByRaw($expression.', e.type')
            ->selectRaw($expression.' AS k, e.type AS type, COUNT(DISTINCT e.sent_mail_id) AS mails')
            ->get();

        foreach ($events as $row) {
            $groups[(string) $row->k][(string) $row->type] = (int) $row->mails;
        }

        return $groups;
    }

    /**
     * @param  array<string, int>  $group
     * @return array{sent: int, delivered: int, opened: int, clicked: int, bounced: int}
     */
    private static function counts(array $group): array
    {
        return [
            'sent' => $group['sent'] ?? 0,
            'delivered' => $group[MailEventType::Delivery->value] ?? 0,
            'opened' => $group[MailEventType::Open->value] ?? 0,
            'clicked' => $group[MailEventType::Click->value] ?? 0,
            'bounced' => $group[MailEventType::Bounce->value] ?? 0,
        ];
    }

    /**
     * @param  array{CarbonImmutable, CarbonImmutable}  $range
     * @return list<array<string, mixed>>
     */
    private function daily(CarbonImmutable $from, CarbonImmutable $to, array $range): array
    {
        $groups = $this->grouped('DATE(m.created_at)', $range);
        $days = [];

        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $days[] = ['date' => $day->toDateString(), ...self::counts($groups[$day->toDateString()] ?? [])];
        }

        return $days;
    }

    /**
     * @param  array{CarbonImmutable, CarbonImmutable}  $range
     * @return list<array<string, mixed>>
     */
    private function byCategory(array $range): array
    {
        $rows = [];

        foreach ($this->grouped('m.category', $range) as $category => $group) {
            $rows[] = ['category' => (string) $category, 'label' => MailCategory::label((string) $category), ...self::counts($group)];
        }

        usort($rows, fn (array $a, array $b): int => $b['sent'] <=> $a['sent']);

        return $rows;
    }

    /**
     * @param  array{CarbonImmutable, CarbonImmutable}  $range
     * @return list<array<string, mixed>>
     */
    private function byProvider(array $range): array
    {
        $providers = [];

        foreach ($this->grouped('m.recipient_domain', $range) as $domain => $group) {
            $provider = RecipientProvider::of((string) $domain);

            foreach (self::counts($group) as $key => $value) {
                $providers[$provider][$key] = ($providers[$provider][$key] ?? 0) + $value;
            }
        }

        uasort($providers, fn (array $a, array $b): int => $b['sent'] <=> $a['sent']);
        $rows = [];

        foreach ($providers as $provider => $counts) {
            $key = count($rows) < self::TOP_PROVIDERS ? (string) $provider : RecipientProvider::OTHER;
            $rows[$key] ??= ['provider' => $key, 'sent' => 0, 'delivered' => 0, 'opened' => 0, 'clicked' => 0, 'bounced' => 0];

            foreach ($counts as $metric => $value) {
                $rows[$key][$metric] += $value;
            }
        }

        return array_values($rows);
    }

    /**
     * @param  array{CarbonImmutable, CarbonImmutable}  $range
     * @return list<array{url: string, clicks: int, mails: int}>
     */
    private function topLinks(array $range): array
    {
        return $this->events($range)
            ->where('e.type', MailEventType::Click->value)
            ->whereNotNull('e.url')
            ->groupBy('e.url')
            ->selectRaw('e.url AS url, COUNT(*) AS clicks, COUNT(DISTINCT e.sent_mail_id) AS mails')
            ->orderByDesc('clicks')
            ->limit(self::TOP_LINKS)
            ->get()
            ->map(fn (object $row): array => ['url' => (string) $row->url, 'clicks' => (int) $row->clicks, 'mails' => (int) $row->mails])
            ->values()
            ->all();
    }

    /**
     * @param  array{CarbonImmutable, CarbonImmutable}  $range
     * @return list<array{reason: string, hard: bool, count: int}>
     */
    private function bounceReasons(array $range): array
    {
        return $this->events($range)
            ->whereIn('e.type', [MailEventType::Bounce->value, MailEventType::SoftBounce->value])
            ->groupBy('e.bounce_category', 'e.type')
            ->selectRaw("COALESCE(e.bounce_category, '') AS reason, e.type AS type, COUNT(*) AS total")
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn (object $row): array => [
                'reason' => (string) $row->reason === '' ? 'Sin categoría' : (string) $row->reason,
                'hard' => $row->type === MailEventType::Bounce->value,
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();
    }
}
