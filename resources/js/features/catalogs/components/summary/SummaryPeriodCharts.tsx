import { BarChart } from '@/components/ui/bar-chart';
import { SectionCard } from '@/components/ui/section-card';
import { StatCard } from '@/components/ui/stat-card';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type SummaryPeriod, type SummaryPeriodTotals, type SummarySeriesPoint } from '@/types/catalog-summary';
import { cilBasket, cilCash, cilChartLine, cilWallet } from '@coreui/icons';
import { type ReactNode } from 'react';

interface SummaryPeriodChartsProps {
    period: SummaryPeriod;
    totals: SummaryPeriodTotals;
    series: readonly SummarySeriesPoint[];
    withParallel: boolean;
}

function includes(totals: SummaryPeriodTotals): ReactNode {
    const parts = [
        totals.fromClosed !== null ? (
            <span key="closed">
                <MoneyLabel money={totals.fromClosed} compact /> de cancelados o vencidos
            </span>
        ) : null,
        totals.unallocated !== null ? (
            <span key="excess">
                <MoneyLabel money={totals.unallocated} compact /> de sobrantes
            </span>
        ) : null,
    ].filter((part) => part !== null);

    return parts.length === 0 ? null : (
        <>Incluye {parts.reduce<ReactNode[]>((all, part, index) => (index === 0 ? [part] : [...all, ' y ', part]), [])}</>
    );
}

function best(series: readonly SummarySeriesPoint[]): SummarySeriesPoint | null {
    return series.reduce<SummarySeriesPoint | null>((top, point) => (point.collected.cents > (top?.collected.cents ?? 0) ? point : top), null);
}

export function SummaryPeriodCharts({ period, totals, series, withParallel }: SummaryPeriodChartsProps) {
    const unit = period.granularity === 'day' ? 'día' : 'mes';
    const top = best(series);
    const empty = series.every((point) => point.collected.cents === 0 && point.layaways === 0);

    return (
        <div className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard label="Cobrado en el periodo" icon={cilCash} tone="success" value={<MoneyLabel money={totals.collected} compact />}>
                    {includes(totals)}
                </StatCard>
                <StatCard label="Pagos recibidos" icon={cilWallet} tone="primary" value={totals.payments}>
                    {totals.average !== null ? (
                        <>
                            Promedio <MoneyLabel money={totals.average} compact />
                        </>
                    ) : null}
                </StatCard>
                <StatCard label="Apartados nuevos" icon={cilBasket} tone="primary" value={totals.layaways} />
                <StatCard label={`El ${unit} que más se cobró`} icon={cilChartLine} tone="warning" value={top === null ? '—' : top.label}>
                    {top !== null ? <MoneyLabel money={top.collected} compact /> : null}
                </StatCard>
            </div>
            {empty ? (
                <p className="text-ink-2 text-sm">No hubo cobros ni apartados nuevos en este periodo.</p>
            ) : (
                <div className={withParallel ? 'grid gap-4 lg:grid-cols-3' : 'grid gap-4 lg:grid-cols-2'}>
                    <SectionCard title={`Cobrado por ${unit}`}>
                        <BarChart
                            label={`Cobrado por ${unit}`}
                            height={160}
                            data={series.map((point) => ({
                                label: point.label,
                                value: Number(point.collected.cents),
                                title: `${point.label}: ${point.collected.usd} en ${point.payments} ${point.payments === 1 ? 'pago' : 'pagos'}`,
                            }))}
                        />
                    </SectionCard>
                    {withParallel ? (
                        <SectionCard title={`En paralelo por ${unit}`}>
                            <BarChart
                                label={`Lo cobrado, en dólares paralelo, por ${unit}`}
                                height={160}
                                data={series.map((point) => ({
                                    label: point.label,
                                    value: Number(point.parallel?.cents ?? 0),
                                    title: `${point.label}: ${point.parallel?.usd ?? '—'} en paralelo`,
                                }))}
                            />
                        </SectionCard>
                    ) : null}
                    <SectionCard title={`Apartados nuevos por ${unit}`}>
                        <BarChart
                            label={`Apartados nuevos por ${unit}`}
                            height={160}
                            data={series.map((point) => ({
                                label: point.label,
                                value: point.layaways,
                                title: `${point.label}: ${point.layaways} ${point.layaways === 1 ? 'apartado' : 'apartados'}`,
                            }))}
                        />
                    </SectionCard>
                </div>
            )}
        </div>
    );
}
