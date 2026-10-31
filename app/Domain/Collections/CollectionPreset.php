<?php

declare(strict_types=1);

namespace App\Domain\Collections;

enum CollectionPreset: string
{
    case AwaitingDownPayment = 'awaiting_down_payment';
    case DueToday = 'due_today';
    case DueWithin7Days = 'due_within_7_days';
    case LongOverdue = 'overdue_over_15_days';
    case FortnightFirstHalf = 'fortnight_first_half';
    case FortnightSecondHalf = 'fortnight_second_half';
    case CompletedNotDelivered = 'completed_not_delivered';

    public const WEEK_DAYS = 7;

    public function label(): string
    {
        return match ($this) {
            self::AwaitingDownPayment => 'Esperando inicial',
            self::DueToday => 'Vencen hoy',
            self::DueWithin7Days => 'Vencen en 7 días',
            self::LongOverdue => 'Mora prolongada',
            self::FortnightFirstHalf => 'Corte del 1 al 15',
            self::FortnightSecondHalf => 'Corte del 16 a fin de mes',
            self::CompletedNotDelivered => 'Pagados sin entregar',
        };
    }

    public function definition(int $longOverdueDays): string
    {
        return match ($this) {
            self::AwaitingDownPayment => 'Apartados que todavía no completan la inicial: arriba, el que vence primero.',
            self::DueToday => 'Cuotas sin cubrir que vencen hoy.',
            self::DueWithin7Days => 'Cuotas sin cubrir que vencen entre hoy y dentro de 7 días.',
            self::LongOverdue => sprintf('Cuotas sin cubrir con más de %d %s de atraso pasada la gracia de tu negocio.', $longOverdueDays, $longOverdueDays === 1 ? 'día' : 'días'),
            self::FortnightFirstHalf => 'Cuotas sin cubrir que vencen entre el 1 y el 15 de este mes.',
            self::FortnightSecondHalf => 'Cuotas sin cubrir que vencen entre el 16 y el último día de este mes.',
            self::CompletedNotDelivered => 'Apartados pagados por completo que todavía no se han entregado.',
        };
    }

    public function listsLayaways(): bool
    {
        return $this === self::CompletedNotDelivered || $this === self::AwaitingDownPayment;
    }

    public function awaitsDownPayment(): bool
    {
        return $this === self::AwaitingDownPayment;
    }
}
