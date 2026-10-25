import { publishedAgo } from '@/features/currency/lib/rateAge';
import { cn } from '@/lib/utils';
import { type RateSource, type RateView } from '@/types/exchange';
import { useId } from 'react';

const FORMAT = new Intl.NumberFormat('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

interface RateSourceCardsProps {
    value: string;
    onChange: (source: string) => void;
    sourceOptions: readonly { value: RateSource; label: string }[];
    rateSources: readonly RateView[];
    manualRate: string | null;
    hint: string;
    error?: string;
}

function details(source: RateSource, rate: RateView | undefined, manualRate: string | null): { figure: string; note: string } {
    if (source === 'manual') {
        const manual = manualRate === null || manualRate === '' ? null : Number(manualRate);

        return { figure: 'Tú la fijas', note: manual === null || Number.isNaN(manual) ? 'Sin cifra todavía' : `Bs. ${FORMAT.format(manual)}` };
    }

    if (rate === undefined || rate.rate === null) {
        return { figure: '—', note: 'Todavía sin tasa registrada' };
    }

    return { figure: `Bs. ${FORMAT.format(rate.rate)}`, note: `${publishedAgo(rate.publishedAt)}${rate.isStale ? ' · desactualizada' : ''}` };
}

export function RateSourceCards({ value, onChange, sourceOptions, rateSources, manualRate, hint, error }: RateSourceCardsProps) {
    const id = useId();

    return (
        <fieldset className="fieldset w-full" aria-describedby={error ? `${id}-error` : `${id}-hint`}>
            <legend className="fieldset-legend">Fuente de la tasa</legend>

            <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-3">
                {sourceOptions.map((option) => {
                    const { figure, note } = details(
                        option.value,
                        rateSources.find((rate) => rate.source === option.value),
                        manualRate,
                    );
                    const checked = value === option.value;

                    return (
                        <label
                            key={option.value}
                            className={cn(
                                'has-[:focus-visible]:outline-primary flex cursor-pointer flex-col gap-1.5 rounded-xl border p-3.5 outline-offset-2 has-[:focus-visible]:outline-2',
                                checked ? 'border-primary bg-primary-soft border-2' : 'border-line-strong bg-base-100',
                            )}
                        >
                            <span className="flex items-center justify-between gap-2">
                                <span id={`${id}-${option.value}`} className="text-sm font-bold">
                                    {option.label}
                                </span>
                                <input
                                    type="radio"
                                    name="exchange_rate_source"
                                    value={option.value}
                                    checked={checked}
                                    onChange={() => onChange(option.value)}
                                    className="radio radio-primary radio-sm"
                                    aria-labelledby={`${id}-${option.value}`}
                                    aria-describedby={`${id}-${option.value}-figure ${id}-${option.value}-note`}
                                />
                            </span>
                            <span id={`${id}-${option.value}-figure`} className="text-lg font-bold">
                                {figure}
                            </span>
                            <span id={`${id}-${option.value}-note`} className="text-ink-3 text-xs">
                                {note}
                            </span>
                        </label>
                    );
                })}
            </div>

            <p id={`${id}-hint`} className="label text-xs">
                {hint}
            </p>
            {error ? (
                <p id={`${id}-error`} role="alert" className="label text-error text-xs">
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
