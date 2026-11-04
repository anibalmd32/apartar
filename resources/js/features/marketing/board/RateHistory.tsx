import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilCheckAlt } from '@coreui/icons';
import { type DailyRate, formatRate, missingDays, weekdayDay } from './board-math';
import { MarkerCircle } from './MarkerCircle';

const SOURCES = ['Tasa BCV', 'Paralelo', 'Tu propia tasa'];

function GapNote({ newer, older }: { newer: string; older: DailyRate }) {
    const missing = missingDays(newer, older.date);

    if (missing.length === 0) {
        return null;
    }

    const days = missing.map(weekdayDay).join(' y ');

    return (
        <li className="font-hand text-pz-ink-3 py-1 pl-6 text-lg leading-snug">
            {days}: sin publicación, vale la del {weekdayDay(older.date)}
        </li>
    );
}

export function RateHistory({ rates }: { rates: DailyRate[] }) {
    return (
        <section
            aria-labelledby="dos-monedas"
            className="mx-auto grid max-w-[1280px] gap-12 px-4 py-16 sm:px-6 lg:grid-cols-[1.2fr_1fr] lg:gap-16 lg:px-12 lg:py-24"
        >
            <div>
                <h2 id="dos-monedas" className="font-vinyl max-w-[12ch] text-[2.75rem] sm:text-6xl lg:text-[4.5rem]">
                    Dos monedas, una sola cuenta
                </h2>
                <p className="text-pz-ink-2 mt-5 max-w-[52ch] text-lg leading-relaxed">
                    Tus precios en dólares, con su equivalente en bolívares a la tasa del día. Cada pago se cuenta con la tasa del día en que se hizo;
                    los fines de semana y feriados vale la del último día hábil.
                </p>
                <p className="font-hand text-pz-ink-3 mt-8 text-xl">Tú eliges de dónde sale la tasa:</p>
                <ul className="mt-2 flex flex-col gap-1.5">
                    {SOURCES.map((source) => (
                        <li key={source} className="font-hand flex items-center gap-3 text-2xl">
                            <Icon icon={cilCheckAlt} size={22} className="text-pz-blue" />
                            {source}
                        </li>
                    ))}
                </ul>
            </div>

            {rates.length === 0 ? null : (
                <figure className="self-end">
                    <figcaption className="font-hand text-pz-ink-3 mb-3 text-2xl">Tasa BCV de los últimos días</figcaption>
                    <ol className="flex flex-col">
                        {rates.flatMap((rate, index) => {
                            const older = rates[index + 1];

                            return [
                                <li
                                    key={rate.date}
                                    className={cn(
                                        'pz-rule-dashed font-hand flex items-baseline justify-between gap-6 py-2 text-[1.625rem] sm:text-[1.875rem]',
                                        index > 0 && 'text-pz-ink-3',
                                    )}
                                >
                                    <span>{weekdayDay(rate.date)}</span>
                                    {index === 0 ? (
                                        <MarkerCircle tone="blue" className="text-pz-blue tabular-nums">
                                            {formatRate(rate.rate)}
                                        </MarkerCircle>
                                    ) : (
                                        <span className="tabular-nums">{formatRate(rate.rate)}</span>
                                    )}
                                </li>,
                                older === undefined ? null : <GapNote key={`${rate.date}-gap`} newer={rate.date} older={older} />,
                            ];
                        })}
                    </ol>
                    <p className="text-pz-ink-3 mt-3 text-sm">Publicada por el Banco Central de Venezuela.</p>
                </figure>
            )}
        </section>
    );
}
