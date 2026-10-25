<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

enum InvoiceNoticeType: string
{
    case Issued = 'issued';

    case Overdue = 'overdue';

    case GraceEndsInThreeDays = 'grace_3_days';

    case GraceEndsInOneDay = 'grace_1_day';

    public static function graceReminderFor(int $daysLeft): ?self
    {
        return match (true) {
            $daysLeft < 0 => null,
            $daysLeft <= 1 => self::GraceEndsInOneDay,
            $daysLeft <= 3 => self::GraceEndsInThreeDays,
            default => null,
        };
    }

    public function subject(): string
    {
        return match ($this) {
            self::Issued => 'Tienes una factura nueva de tu suscripción en Apartar',
            self::Overdue => 'Tu factura de Apartar venció',
            self::GraceEndsInThreeDays => 'Se acaba el plazo para pagar tu suscripción de Apartar',
            self::GraceEndsInOneDay => 'Último aviso: paga tu suscripción de Apartar para que no se suspenda',
        };
    }
}
