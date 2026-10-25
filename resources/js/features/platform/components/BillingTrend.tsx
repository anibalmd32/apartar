import { BarChart } from '@/components/ui/bar-chart';
import { SectionCard } from '@/components/ui/section-card';

const MONTHS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
const USD = new Intl.NumberFormat('es-VE', { maximumFractionDigits: 0 });

export function versusLastMonth(monthly: readonly { readonly cents: number }[] | null): string | null {
    const current = monthly?.[monthly.length - 1]?.cents;
    const previous = monthly?.[monthly.length - 2]?.cents;

    if (current === undefined || previous === undefined || previous === 0) {
        return null;
    }

    const change = ((current - previous) / previous) * 100;

    return `${change >= 0 ? '+' : ''}${change.toLocaleString('es-VE', { maximumFractionDigits: 1 })}% vs. mes pasado`;
}

export function BillingTrend({ monthly }: { monthly: readonly { readonly month: string; readonly cents: number }[] | null }) {
    return (
        <SectionCard title="Facturación recurrente" subtitle="Lo facturado cada mes, sin anuladas.">
            {monthly === null ? (
                <p>—</p>
            ) : (
                <BarChart
                    label="Facturado por mes"
                    data={monthly.map((row) => ({
                        label: MONTHS[Number(row.month.slice(5, 7)) - 1] ?? row.month,
                        value: row.cents,
                        title: `$${USD.format(row.cents / 100)}`,
                    }))}
                />
            )}
        </SectionCard>
    );
}
