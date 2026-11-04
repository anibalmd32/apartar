import { Icon } from '@/components/ui/icon';
import { cilCheckAlt } from '@coreui/icons';
import { type DailyRate, formatRate, shortDay } from './board-math';
import { MarkerCircle } from './MarkerCircle';

const SOURCES = ['Tasa BCV', 'Paralelo', 'Tu propia tasa'];

const FADE = [1, 0.72, 0.6, 0.52, 0.46, 0.42];

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
                <ul className="mt-8 flex flex-col gap-2">
                    {SOURCES.map((source) => (
                        <li key={source} className="font-hand flex items-center gap-3 text-2xl">
                            <Icon icon={cilCheckAlt} size={22} className="text-pz-green" />
                            {source}
                        </li>
                    ))}
                </ul>
            </div>

            {rates.length === 0 ? null : (
                <figure className="self-end">
                    <figcaption className="font-hand text-pz-ink-3 mb-3 text-2xl">Tasa BCV de los últimos días</figcaption>
                    <ol className="flex flex-col">
                        {rates.map((rate, index) => (
                            <li
                                key={rate.date}
                                style={{ opacity: FADE[index] ?? 0.42 }}
                                className="pz-rule-dashed font-hand flex items-baseline justify-between gap-6 py-2 text-[1.625rem] sm:text-[1.875rem]"
                            >
                                <span>{shortDay(rate.date)}</span>
                                {index === 0 ? (
                                    <MarkerCircle tone="blue" className="text-pz-blue tabular-nums">
                                        {formatRate(rate.rate)}
                                    </MarkerCircle>
                                ) : (
                                    <span className="tabular-nums">{formatRate(rate.rate)}</span>
                                )}
                            </li>
                        ))}
                    </ol>
                    <p className="text-pz-ink-3 mt-3 text-sm">Publicada por el Banco Central de Venezuela.</p>
                </figure>
            )}
        </section>
    );
}
