import { cn } from '@/lib/utils';
import { type DailyRate, formatRate, longDay, shortDay } from './board-math';

interface RateLineProps {
    today: string;
    rates: DailyRate[];
    className?: string;
}

export function RateLine({ today, rates, className }: RateLineProps) {
    const [latest, previous] = rates;

    return (
        <p className={cn('font-hand relative text-[1.375rem] leading-snug sm:text-[1.75rem]', className)}>
            <span className="first-letter:uppercase">{`Hoy, ${longDay(today)}`}</span>
            {latest === undefined ? null : (
                <>
                    <span aria-hidden="true"> · </span>
                    <span className="whitespace-nowrap">
                        Tasa BCV <span className="text-pz-blue">{formatRate(latest.rate)}</span>
                    </span>
                    {latest.date === today ? null : (
                        <span className="text-pz-ink-3 block text-lg sm:inline sm:text-xl"> (publicada el {shortDay(latest.date)})</span>
                    )}
                </>
            )}
            {previous === undefined ? null : (
                <span
                    aria-hidden="true"
                    className="text-pz-ghost pointer-events-none absolute top-[0.9em] left-[38%] hidden -rotate-1 select-none lg:block"
                >
                    Tasa BCV {formatRate(previous.rate)}
                </span>
            )}
        </p>
    );
}
