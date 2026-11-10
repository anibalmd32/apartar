import { Chip } from '@/components/ui/chip';
import { DateRangeField } from '@/components/ui/date-range-field';
import { ScrollRow } from '@/components/ui/scroll-row';
import { type SummaryPeriod } from '@/types/catalog-summary';
import { router } from '@inertiajs/react';

interface SummaryPeriodFilterProps {
    period: SummaryPeriod;
    url: string;
}

export function SummaryPeriodFilter({ period, url }: SummaryPeriodFilterProps) {
    const go = (from: string, to: string) => {
        if (from === '' || to === '' || from > to) {
            return;
        }

        router.get(url, { desde: from, hasta: to }, { preserveScroll: true, preserveState: true, replace: true });
    };

    return (
        <section aria-label="Periodo" className="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
            <ScrollRow role="group" aria-label="Periodos rápidos" activeKey={period.preset} className="flex gap-2 lg:flex-wrap lg:overflow-visible">
                {period.presets.map((preset) => (
                    <Chip key={preset.key} data-key={preset.key} active={period.preset === preset.key} onClick={() => go(preset.from, preset.to)}>
                        {preset.label}
                    </Chip>
                ))}
            </ScrollRow>
            <DateRangeField
                label="Periodo"
                fromLabel="Desde"
                toLabel="Hasta"
                from={period.from}
                to={period.to}
                onFromChange={(event) => go(event.target.value, period.to)}
                onToChange={(event) => go(period.from, event.target.value)}
                className="lg:max-w-md"
            />
        </section>
    );
}
