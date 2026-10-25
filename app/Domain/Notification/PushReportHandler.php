<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\ReportHandlerInterface;
use NotificationChannels\WebPush\WebPushMessageInterface;

final class PushReportHandler implements ReportHandlerInterface
{
    public function handleReport(MessageSentReport $report, PushSubscription $subscription, WebPushMessageInterface $message): void
    {
        if ($report->isSuccess()) {
            $subscription->forceFill(['last_used_at' => CarbonImmutable::now()])->save();

            return;
        }

        if ($report->isSubscriptionExpired()) {
            $subscription->delete();
        }

        Log::warning('push.failed', [
            'status' => $report->getResponse()?->getStatusCode(),
            'reason' => mb_substr($report->getReason(), 0, 200),
            'expired' => $report->isSubscriptionExpired(),
        ]);
    }
}
