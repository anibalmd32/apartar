<?php

declare(strict_types=1);

namespace App\Domain\Collections;

/**
 * Los filtros listos de la cobranza (`REQ-BOF-042` RB-2).
 *
 * Las fechas son **días del negocio**, en su zona, y la mora cuenta con
 * **su** gracia (RB-4): `CollectionFilters` las resuelve con el calendario de
 * la organización fijada. Los que miran cuotas se quedan con las **sin
 * cubrir** —es una lista de trabajo: lo que hay que cobrar—, y excluyen los
 * apartados terminales (RB-5). `completed_not_delivered` es de apartados, no
 * de cuotas: lo que falta ahí es entregar.
 */
enum CollectionPreset: string
{
    case DueToday = 'due_today';
    case DueWithin7Days = 'due_within_7_days';
    case OverdueOver15Days = 'overdue_over_15_days';
    case FortnightFirstHalf = 'fortnight_first_half';
    case FortnightSecondHalf = 'fortnight_second_half';
    case CompletedNotDelivered = 'completed_not_delivered';

    /** «Más de 15 días pasada la gracia». */
    public const LONG_OVERDUE_DAYS = 15;

    /** «Entre hoy y hoy + 7». */
    public const WEEK_DAYS = 7;

    public function label(): string
    {
        return match ($this) {
            self::DueToday => 'Vencen hoy',
            self::DueWithin7Days => 'Vencen en 7 días',
            self::OverdueOver15Days => 'Mora de más de 15 días',
            self::FortnightFirstHalf => 'Corte del 1 al 15',
            self::FortnightSecondHalf => 'Corte del 16 a fin de mes',
            self::CompletedNotDelivered => 'Pagados sin entregar',
        };
    }

    /**
     * La definición exacta, para el estado vacío (SP-1): qué habría que tener
     * para que la lista no estuviera vacía.
     */
    public function definition(): string
    {
        return match ($this) {
            self::DueToday => 'Cuotas sin cubrir que vencen hoy.',
            self::DueWithin7Days => 'Cuotas sin cubrir que vencen entre hoy y dentro de 7 días.',
            self::OverdueOver15Days => 'Cuotas sin cubrir con más de 15 días de atraso pasada la gracia de tu negocio.',
            self::FortnightFirstHalf => 'Cuotas sin cubrir que vencen entre el 1 y el 15 de este mes.',
            self::FortnightSecondHalf => 'Cuotas sin cubrir que vencen entre el 16 y el último día de este mes.',
            self::CompletedNotDelivered => 'Apartados pagados por completo que todavía no se han entregado.',
        };
    }

    /** Si la lista es de apartados en vez de cuotas. */
    public function listsLayaways(): bool
    {
        return $this === self::CompletedNotDelivered;
    }
}
