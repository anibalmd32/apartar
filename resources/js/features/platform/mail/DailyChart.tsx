import { BarChart, type BarDatum } from '@/components/ui/bar-chart';
import { FilterTabs } from '@/components/ui/filter-tabs';
import { SectionCard } from '@/components/ui/section-card';
import { type MailCounts, type MailMetrics } from '@/types/mail';
import { useState } from 'react';
import { count } from './format';

type Series = keyof MailCounts;

const SERIES: readonly { value: Series; label: string }[] = [
    { value: 'sent', label: 'Enviados' },
    { value: 'delivered', label: 'Entregados' },
    { value: 'opened', label: 'Abiertos' },
    { value: 'clicked', label: 'Con clic' },
    { value: 'bounced', label: 'Rebotados' },
];

const DAY = new Intl.DateTimeFormat('es', { day: 'numeric', month: 'short', timeZone: 'UTC' });

export const WEEKLY_AFTER_DAYS = 45;

export function chartData(daily: MailMetrics['daily'], series: Series): BarDatum[] {
    const weekly = daily.length > WEEKLY_AFTER_DAYS;
    const buckets: { label: string; value: number }[] = [];

    daily.forEach((day, index) => {
        const label = DAY.format(new Date(`${day.date}T00:00:00Z`));

        if (!weekly || index % 7 === 0) {
            buckets.push({ label: weekly ? `Sem. ${label}` : label, value: 0 });
        }

        const bucket = buckets[buckets.length - 1];

        if (bucket !== undefined) {
            bucket.value += day[series];
        }
    });

    return buckets.map((bucket) => ({ label: bucket.label, value: bucket.value, title: `${bucket.label}: ${count(bucket.value)}` }));
}

export function DailyChart({ daily }: { daily: MailMetrics['daily'] }) {
    const [series, setSeries] = useState<Series>('sent');
    const label = SERIES.find((option) => option.value === series)?.label ?? '';

    return (
        <SectionCard
            title="Evolución"
            subtitle={daily.length > WEEKLY_AFTER_DAYS ? 'Por semana, según el día de envío.' : 'Por día, según el día de envío.'}
            aria-label="Evolución"
        >
            <FilterTabs label="Serie" tabs={SERIES} value={series} onChange={setSeries} />
            <BarChart label={`${label} por día`} data={chartData(daily, series)} />
        </SectionCard>
    );
}
