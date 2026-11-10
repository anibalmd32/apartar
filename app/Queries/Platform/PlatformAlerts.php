<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\SubscriptionStatus;
use App\Support\Surface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PlatformAlerts
{
    public const GRACE_ENDING_HOURS = 72;

    /**
     * @return list<array{key: string, count: int, tone: string, href: string}>
     */
    public function for(int $operatorId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $alerts = [
            [
                'key' => 'lateReceipts',
                'count' => DB::table('subscription_receipts')
                    ->where('status', ReceiptStatus::PendingReview->value)
                    ->where('created_at', '<', $now->subHours(SubscriptionReceiptInbox::LATE_HOURS))
                    ->count(),
                'tone' => 'warning',
                'href' => Surface::platformPath().'/facturas',
            ],
            [
                'key' => 'latePhotoReviews',
                'count' => app(ProductPhotoReviewInbox::class)->lateCount($now),
                'tone' => 'warning',
                'href' => Surface::platformPath().'/revisiones',
            ],
            [
                'key' => 'graceEnding',
                'count' => DB::table('subscriptions')
                    ->whereIn('status', [SubscriptionStatus::PastDue->value, SubscriptionStatus::Grace->value])
                    ->whereBetween('grace_ends_at', [$now, $now->addHours(self::GRACE_ENDING_HOURS)])
                    ->count(),
                'tone' => 'error',
                'href' => Surface::platformPath().'/organizaciones?vencidas=1',
            ],
            [
                'key' => 'overdueInvoices',
                'count' => DB::table('subscription_invoices')->where('status', InvoiceStatus::Overdue->value)->distinct()->count('organization_id'),
                'tone' => 'warning',
                'href' => Surface::platformPath().'/organizaciones?vencidas=1',
            ],
            [
                'key' => 'myOpenSupport',
                'count' => DB::table('support_access_grants')
                    ->where('platform_user_id', $operatorId)
                    ->whereNull('revoked_at')
                    ->where('expires_at', '>', $now)
                    ->count(),
                'tone' => 'info',
                'href' => Surface::platformPath().'/soporte',
            ],
            [
                'key' => 'failedJobs',
                'count' => DB::table('failed_jobs')->count(),
                'tone' => 'error',
                'href' => self::monitorUrl(),
            ],
        ];

        return array_values(array_filter($alerts, fn (array $alert): bool => $alert['count'] > 0));
    }

    private static function monitorUrl(): string
    {
        $domain = config('monitor.domain');
        $path = '/'.config('pulse.path', 'pulse');

        return is_string($domain) && $domain !== '' ? 'https://'.$domain.$path : $path;
    }
}
