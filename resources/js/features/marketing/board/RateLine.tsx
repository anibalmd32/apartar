import { cn } from '@/lib/utils';
import { type DailyRate, formatRate, longDay, shortDay, weekdayDay } from './board-math';

interface RateLineProps {
    today: string;
    rates: DailyRate[];
    className?: string;
}

export function RateLine({ today, rates, className }: RateLineProps) {
    const [latest, previous] = rates;

    return (
        <div className={cn('font-hand flex flex-col gap-1 text-[1.375rem] leading-snug sm:text-[1.75rem]', className)}>
            <p>
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
            </p>
            {previous === undefined ? null : (
                <p className="text-pz-ink-3 decoration-pz-ink-3/60 -rotate-1 text-lg line-through decoration-2 opacity-70 sm:ml-[30%] sm:text-xl">
                    <span className="sr-only">Antes: </span>
                    {weekdayDay(previous.date)} · Tasa BCV {formatRate(previous.rate)}
                </p>
            )}
        </div>
    );
}
