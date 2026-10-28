<?php

declare(strict_types=1);

namespace App\Domain\Mail;

enum MailEventType: string
{
    case Delivery = 'delivery';
    case Open = 'open';
    case Click = 'click';
    case Unsubscribe = 'unsubscribe';
    case Spam = 'spam';
    case SoftBounce = 'soft_bounce';
    case Bounce = 'bounce';
    case Suspension = 'suspension';
    case Reject = 'reject';

    public static function fromMailtrap(mixed $name): ?self
    {
        if (! is_string($name)) {
            return null;
        }

        $normalised = str_replace([' ', '-'], '_', strtolower(trim($name)));

        return match ($normalised) {
            'spam_complaint' => self::Spam,
            default => self::tryFrom($normalised),
        };
    }

    public function status(): MailStatus
    {
        return match ($this) {
            self::Delivery => MailStatus::Delivered,
            self::Open => MailStatus::Opened,
            self::Click => MailStatus::Clicked,
            self::Unsubscribe => MailStatus::Unsubscribed,
            self::Spam => MailStatus::Spam,
            self::SoftBounce => MailStatus::SoftBounced,
            self::Bounce => MailStatus::Bounced,
            self::Suspension => MailStatus::Suspended,
            self::Reject => MailStatus::Rejected,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'Entregado',
            self::Open => 'Abierto',
            self::Click => 'Clic',
            self::Unsubscribe => 'Se dio de baja',
            self::Spam => 'Marcado como spam',
            self::SoftBounce => 'Rebote temporal',
            self::Bounce => 'Rebote',
            self::Suspension => 'Suspendido',
            self::Reject => 'Rechazado',
        };
    }
}
