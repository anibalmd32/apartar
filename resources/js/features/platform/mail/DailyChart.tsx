import { BarChart, type BarDatum } from '@/components/ui/bar-chart';
import { SectionCard } from '@/components/ui/section-card';
import { cn } from '@/lib/utils';
import { type MailCounts, type MailMetrics } from '@/types/mail';
import { useState } from 'react';
import { count } from './format';

type Series = keyof MailCounts;

const SERIES: readonly { value: Series; label: string; none: string }[] = [
    { value: 'sent', label: 'Enviados', none: 'Ningún correo enviado' },
    { value: 'delivered', label: 'Entregados', none: 'Ningún correo entregado' },
    { value: 'opened', label: 'Abiertos', none: 'Ningún correo abierto' },
    { value: 'clicked', label: 'Con clic', none: 'Ningún clic' },
    { value: 'bounced', label: 'Rebotados', none: 'Ningún rebote' },
];

const DAY = new Intl.DateTimeFormat('es', { day: 'numeric', month: 'short', timeZone: 'UTC' });

export const WEEKLY_AFTER_DAYS = 45;

export function chartData(daily: MailMetrics['daily'], series: Series): BarDatum[] {
    const weekly = daily.length > WEEKLY_AFTER_DAYS;
    const buckets: { label: string; full: string; value: number }[] = [];

    daily.forEach((day, index) => {
        const full = DAY.format(new Date(`${day.date}T00:00:00Z`)).replace('.', '');

        if (!weekly || index % 7 === 0) {
            buckets.push({ label: full, full: weekly ? `Semana del ${full}` : full, value: 0 });
        }

        const bucket = buckets[buckets.length - 1];

        if (bucket !== undefined) {
            bucket.value += day[series];
        }
    });

    return buckets.map((bucket) => ({ label: bucket.label, value: bucket.value, title: `${bucket.full}: ${count(bucket.value)}` }));
}

function SeriesPicker({ value, onChange }: { value: Series; onChange: (series: Series) => void }) {
    return (
        <div role="group" aria-label="Serie" className="flex flex-wrap gap-1.5">
            {SERIES.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={option.value === value}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'hit-area h-8 rounded-full border px-3 text-[13px] whitespace-nowrap transition-colors',
                        option.value === value
                            ? 'border-primary bg-primary-soft text-primary-ink font-bold'
                            : 'border-line text-ink-2 hover:text-base-content font-medium',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

export function DailyChart({ daily }: { daily: MailMetrics['daily'] }) {
    const [series, setSeries] = useState<Series>('sent');
    const current = SERIES.find((option) => option.value === series) ?? SERIES[0]!;
    const data = chartData(daily, series);
    const withData = data.filter((bar) => bar.value > 0).length;

    return (
        <SectionCard
            title="Evolución"
            subtitle={daily.length > WEEKLY_AFTER_DAYS ? 'Por semana, según el día de envío.' : 'Por día, según el día de envío.'}
            aria-label="Evolución"
        >
            <SeriesPicker value={series} onChange={setSeries} />
            {withData < 2 ? (
                <p className="bg-surface-2 text-ink-2 rounded-xl px-4 py-3 text-sm">
                    {withData === 0 ? `${current.none} en estas fechas.` : 'Todavía hay pocos datos para ver una tendencia.'}
                </p>
            ) : (
                <BarChart label={`${current.label} por día`} data={data} />
            )}
        </SectionCard>
    );
}
