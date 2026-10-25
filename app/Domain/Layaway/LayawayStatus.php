<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

enum LayawayStatus: string
{
    case PendingDownPayment = 'pending_down_payment';

    case Active = 'active';

    case Overdue = 'overdue';

    case Completed = 'completed';

    case Cancelled = 'cancelled';

    case Expired = 'expired';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingDownPayment => [self::Active, self::Expired, self::Cancelled],
            self::Active => [self::Overdue, self::Completed, self::Cancelled],
            self::Overdue => [self::Active, self::Completed, self::Cancelled],
            self::Completed, self::Cancelled, self::Expired => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::PendingDownPayment, self::Active, self::Overdue => false,
            self::Completed, self::Cancelled, self::Expired => true,
        };
    }

    public function acceptsPayments(): bool
    {
        return match ($this) {
            self::PendingDownPayment, self::Active, self::Overdue => true,
            self::Completed, self::Cancelled, self::Expired => false,
        };
    }

    public function countsTowardPlanLimit(): bool
    {
        return match ($this) {
            self::PendingDownPayment, self::Active, self::Overdue => true,
            self::Completed, self::Cancelled, self::Expired => false,
        };
    }

    public function releasesStock(): bool
    {
        return match ($this) {
            self::Expired, self::Cancelled => true,
            self::PendingDownPayment, self::Active, self::Overdue, self::Completed => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function holdingStock(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => ! $status->isTerminal()));
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingDownPayment => 'Esperando la inicial',
            self::Active => 'Activo',
            self::Overdue => 'En mora',
            self::Completed => 'Pagado',
            self::Cancelled => 'Cancelado',
            self::Expired => 'Expirado',
        };
    }

    public function customerLabel(): string
    {
        return match ($this) {
            self::PendingDownPayment => 'Esperando la inicial',
            self::Active => 'Al día',
            self::Overdue => 'Con cuotas vencidas',
            self::Completed => 'Pagado',
            self::Cancelled => 'Cancelado',
            self::Expired => 'Vencido sin inicial',
        };
    }
}
