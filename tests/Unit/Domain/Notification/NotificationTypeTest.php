<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Notification;

use App\Domain\Notification\NotificationAudience;
use App\Domain\Notification\NotificationType;
use App\Domain\Subscription\PlanFeature;
use PHPUnit\Framework\TestCase;

final class NotificationTypeTest extends TestCase
{
    public function test_transactional_notices_do_not_depend_on_the_plan(): void
    {
        foreach ([NotificationType::LayawayActivated, NotificationType::PaymentCredited, NotificationType::ReceiptRejected, NotificationType::UnreadMessage] as $type) {
            $this->assertNull($type->pushFeature(), $type->value);
        }

        $this->assertSame(PlanFeature::WebPush, NotificationType::InstallmentReminder->pushFeature());
        $this->assertSame(PlanFeature::WebPush, NotificationType::ManualPush->pushFeature());
        $this->assertSame(PlanFeature::SubscriberDigest, NotificationType::CatalogDigest->pushFeature());
    }

    public function test_chat_manual_and_digest_never_go_by_mail(): void
    {
        foreach ([NotificationType::UnreadMessage, NotificationType::ManualPush, NotificationType::CatalogDigest] as $type) {
            $this->assertSame(['webpush'], $type->channels(), $type->value);
        }

        $this->assertSame(['mail', 'webpush'], NotificationType::InstallmentReminder->channels());
    }

    public function test_each_type_has_its_audience(): void
    {
        $this->assertSame(NotificationAudience::Owner, NotificationType::InvoiceOverdue->audience());
        $this->assertSame(NotificationAudience::Subscriber, NotificationType::CatalogDigest->audience());
        $this->assertSame(NotificationAudience::Customer, NotificationType::DownPaymentReminder->audience());
    }
}
