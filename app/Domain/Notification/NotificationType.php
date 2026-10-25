<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Domain\Subscription\PlanFeature;

enum NotificationType: string
{
    case InstallmentReminder = 'installment_reminder';
    case DownPaymentReminder = 'down_payment_reminder';
    case LayawayActivated = 'layaway_activated';
    case PaymentCredited = 'payment_credited';
    case ReceiptRejected = 'receipt_rejected';
    case LayawayCompleted = 'layaway_completed';
    case LayawayCancelled = 'layaway_cancelled';
    case LayawayExpired = 'layaway_expired';
    case LayawayDelivered = 'layaway_delivered';
    case UnreadMessage = 'unread_message';
    case ManualPush = 'manual_push';
    case CatalogDigest = 'catalog_digest';
    case InvoiceIssued = 'invoice_issued';
    case InvoiceOverdue = 'invoice_overdue';
    case SubscriptionSuspended = 'subscription_suspended';

    /**
     * @return list<string>
     */
    public function channels(): array
    {
        return match ($this) {
            self::UnreadMessage, self::ManualPush, self::CatalogDigest => ['webpush'],
            self::InvoiceIssued, self::InvoiceOverdue, self::SubscriptionSuspended => ['mail'],
            default => ['mail', 'webpush'],
        };
    }

    public function audience(): NotificationAudience
    {
        return match ($this) {
            self::InvoiceIssued, self::InvoiceOverdue, self::SubscriptionSuspended => NotificationAudience::Owner,
            self::CatalogDigest => NotificationAudience::Subscriber,
            default => NotificationAudience::Customer,
        };
    }

    public function pushFeature(): ?PlanFeature
    {
        return match ($this) {
            self::InstallmentReminder, self::DownPaymentReminder, self::ManualPush => PlanFeature::WebPush,
            self::CatalogDigest => PlanFeature::SubscriberDigest,
            default => null,
        };
    }
}
