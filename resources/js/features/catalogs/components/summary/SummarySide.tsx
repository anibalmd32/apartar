import { Badge } from '@/components/ui/badge';
import { BarChart } from '@/components/ui/bar-chart';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { LAYAWAY_TONES, toneOf } from '@/lib/status-tones';
import { type CatalogSummaryProps } from '@/types/catalog-summary';
import { Link } from '@inertiajs/react';

const STATUS_LABELS: Readonly<Record<string, string>> = {
    pending_down_payment: 'Esperando la inicial',
    active: 'Activos',
    overdue: 'En mora',
    completed: 'Pagados',
    cancelled: 'Cancelados',
    expired: 'Vencidos',
};

const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];

function monthLabel(month: string): string {
    const [year, number] = month.split('-');

    return `${MONTHS[Number(number) - 1] ?? number} ${year?.slice(2) ?? ''}`;
}

export function SummaryCollected({ collected }: { collected: CatalogSummaryProps['collected'] }) {
    const data = collected.map((month) => ({
        label: monthLabel(month.month),
        value: Number(month.amount.cents),
        title: `${monthLabel(month.month)}: ${month.amount.usd}`,
    }));

    return data.every((datum) => datum.value === 0) ? (
        <p className="text-ink-2 text-sm">Todavía no hay cobros en los últimos meses.</p>
    ) : (
        <BarChart data={data} label="Cobrado por mes" height={140} />
    );
}

export function SummaryStatuses({ statuses }: { statuses: CatalogSummaryProps['statuses'] }) {
    return (
        <ul className="flex flex-col gap-2" aria-label="Apartados por estado">
            {Object.entries(STATUS_LABELS).map(([status, label]) => (
                <li key={status} className="flex items-center justify-between gap-3 text-sm">
                    <Badge tone={toneOf(LAYAWAY_TONES, status)}>{label}</Badge>
                    <span className="font-semibold">{statuses[status] ?? 0}</span>
                </li>
            ))}
        </ul>
    );
}

export function SummaryDebtors({ debtors }: { debtors: CatalogSummaryProps['debtors'] }) {
    if (debtors.length === 0) {
        return <p className="text-ink-2 text-sm">Nadie debe nada en este catálogo.</p>;
    }

    return (
        <ul className="divide-line flex flex-col divide-y" aria-label="Clientes que más deben">
            {debtors.map((debtor) => (
                <li key={debtor.url} className="flex items-center justify-between gap-3 py-2.5 text-sm first:pt-0 last:pb-0">
                    <span className="flex min-w-0 flex-col">
                        <Link href={debtor.url} className="hit-area truncate font-semibold hover:underline">
                            {debtor.name}
                        </Link>
                        <span className="text-ink-3 text-xs">
                            {debtor.layaways} {debtor.layaways === 1 ? 'apartado' : 'apartados'}
                        </span>
                    </span>
                    <MoneyLabel money={debtor.remaining} className="font-semibold" compact />
                </li>
            ))}
        </ul>
    );
}
