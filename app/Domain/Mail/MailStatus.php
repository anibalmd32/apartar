<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use Carbon\CarbonInterface;

enum MailStatus: string
{
    case Sending = 'sending';
    case Sent = 'sent';
    case SoftBounced = 'soft_bounced';
    case Delivered = 'delivered';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Unsubscribed = 'unsubscribed';
    case Spam = 'spam';
    case Bounced = 'bounced';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Failed = 'failed';

    public const PENDING_TIMEOUT_MINUTES = 15;

    public function rank(): int
    {
        return match ($this) {
            self::Sending => 0,
            self::Sent => 1,
            self::SoftBounced => 2,
            self::Delivered => 3,
            self::Opened => 4,
            self::Clicked => 5,
            self::Unsubscribed => 6,
            self::Spam => 7,
            self::Bounced, self::Rejected, self::Suspended, self::Failed => 8,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Sending => 'Enviando',
            self::Sent => 'Enviado',
            self::SoftBounced => 'Rebote temporal',
            self::Delivered => 'Entregado',
            self::Opened => 'Abierto',
            self::Clicked => 'Con clic',
            self::Unsubscribed => 'Baja',
            self::Spam => 'Marcado como spam',
            self::Bounced => 'Rebotado',
            self::Rejected => 'Rechazado',
            self::Suspended => 'Suspendido',
            self::Failed => 'Falló',
        };
    }

    public function isProblem(): bool
    {
        return in_array($this, [self::SoftBounced, self::Unsubscribed, self::Spam, self::Bounced, self::Rejected, self::Suspended, self::Failed], true);
    }

    public function advancedBy(self $next): self
    {
        return $next->rank() >= $this->rank() ? $next : $this;
    }

    public function effective(?CarbonInterface $createdAt, CarbonInterface $now): self
    {
        if ($this !== self::Sending || $createdAt === null) {
            return $this;
        }

        return $createdAt->lessThan($now->copy()->subMinutes(self::PENDING_TIMEOUT_MINUTES)) ? self::Failed : $this;
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $status) {
            $labels[$status->value] = $status->label();
        }

        return $labels;
    }
}
