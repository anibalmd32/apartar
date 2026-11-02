import { BarChart } from '@/components/ui/bar-chart';
import { SectionCard } from '@/components/ui/section-card';

interface MonthTotal {
    readonly month: string;
    readonly cents: number;
}

const MONTHS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
const MONTH_NAMES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const USD = new Intl.NumberFormat('es-VE', { maximumFractionDigits: 0 });

const monthName = (row: MonthTotal) => MONTH_NAMES[Number(row.month.slice(5, 7)) - 1] ?? row.month;
const usd = (cents: number) => `$${USD.format(cents / 100)}`;

export function versusPreviousMonth(monthly: readonly MonthTotal[] | null): string | null {
    const closed = monthly?.[monthly.length - 2];
    const before = monthly?.[monthly.length - 3];

    if (closed === undefined || before === undefined || before.cents === 0) {
        return null;
    }

    const change = ((closed.cents - before.cents) / before.cents) * 100;

    return `${change >= 0 ? '+' : ''}${change.toLocaleString('es-VE', { maximumFractionDigits: 1 })}% vs. ${monthName(before)}`;
}

function Headline({ monthly }: { monthly: readonly MonthTotal[] }) {
    const current = monthly[monthly.length - 1];
    const closed = monthly[monthly.length - 2];
    const delta = versusPreviousMonth(monthly);

    return (
        <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
            {closed ? (
                <div>
                    <p className="text-ink-3 text-xs">Facturado en {monthName(closed)}</p>
                    <p className="text-figure text-2xl">{usd(closed.cents)}</p>
                    {delta ? <p className="text-ink-2 text-xs">{delta}</p> : null}
                </div>
            ) : null}
            {current ? (
                <p className="text-ink-3 text-xs">
                    {monthName(current).replace(/^./, (letter) => letter.toUpperCase())}, en curso:{' '}
                    <strong className="text-ink-2">{usd(current.cents)}</strong>
                </p>
            ) : null}
        </div>
    );
}

export function BillingTrend({ monthly }: { monthly: readonly MonthTotal[] | null }) {
    const billedMonths = monthly?.filter((row) => row.cents > 0).length ?? 0;

    return (
        <SectionCard title="Facturación recurrente" subtitle="Lo facturado cada mes, sin anuladas.">
            {monthly === null ? (
                <p>—</p>
            ) : (
                <>
                    <Headline monthly={monthly} />
                    {billedMonths < 2 ? (
                        <p className="bg-surface-2 text-ink-2 rounded-xl px-4 py-3 text-sm">
                            Todavía hay pocos datos: el gráfico aparece cuando haya dos meses facturados.
                        </p>
                    ) : (
                        <BarChart
                            label="Facturado por mes"
                            data={monthly.map((row) => ({
                                label: MONTHS[Number(row.month.slice(5, 7)) - 1] ?? row.month,
                                value: row.cents,
                                title: usd(row.cents),
                            }))}
                        />
                    )}
                </>
            )}
        </SectionCard>
    );
}
