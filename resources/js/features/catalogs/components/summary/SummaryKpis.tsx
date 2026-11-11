import { Icon } from '@/components/ui/icon';
import { AmountProgress } from '@/features/collections/components/AmountProgress';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { cn } from '@/lib/utils';
import { type CatalogSummaryProps } from '@/types/catalog-summary';
import { cilCalendar, cilWarning } from '@coreui/icons';
import { type ReactNode } from 'react';

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

function Pending({ icon, label, children, alert }: { icon: string[]; label: string; children: ReactNode; alert: boolean }) {
    return (
        <div className="flex min-w-0 flex-1 items-center gap-2.5 py-2.5 sm:py-0">
            <Icon icon={icon} size={18} className={cn('shrink-0', alert ? 'text-error' : 'text-ink-3')} />
            <span className="text-ink-2 text-[13px]">{label}</span>
            <span
                className={cn(
                    'ml-auto flex flex-col items-end text-sm font-semibold sm:ml-2 sm:flex-row sm:items-baseline sm:gap-1.5',
                    alert && 'text-error',
                )}
            >
                {children}
            </span>
        </div>
    );
}

export function SummaryKpis({ totals }: { totals: CatalogSummaryProps['totals'] }) {
    const overdue = Number(totals.overdue.cents) > 0;

    return (
        <section aria-label="Resumen de ventas" className="bg-base-100 border-line flex flex-col gap-3 rounded-[14px] border p-4 md:p-5">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="flex flex-col">
                    <span className="text-ink-3 text-[13px]">Vendido en apartados</span>
                    <MoneyLabel money={totals.committed} className="text-2xl font-bold" />
                    <span className="text-ink-3 text-xs">
                        {plural(totals.layaways, 'apartado', 'apartados')} · {plural(totals.units, 'unidad', 'unidades')}
                    </span>
                </div>
                <div className="flex flex-col items-end">
                    <span className="text-ink-3 text-[13px]">Por cobrar</span>
                    <MoneyLabel money={totals.remaining} className="text-xl font-bold" />
                </div>
            </div>
            <div className="flex flex-col gap-1">
                <AmountProgress paid={totals.paid} total={totals.committed} progress={totals.progress} />
                <span className="text-ink-3 text-xs">Lo abonado a los apartados vigentes, sin cancelados, vencidos ni sobrantes.</span>
            </div>
            <div className="border-line divide-line flex flex-col divide-y border-t pt-1 sm:flex-row sm:gap-6 sm:divide-y-0 sm:pt-3">
                <Pending icon={cilWarning} label="Vencido sin pagar" alert={overdue}>
                    <MoneyLabel money={totals.overdue} compact />
                    {overdue ? (
                        <span className="text-xs font-normal">{plural(totals.overdueLayaways, 'apartado atrasado', 'apartados atrasados')}</span>
                    ) : null}
                </Pending>
                <Pending icon={cilCalendar} label="Vence en 7 días" alert={false}>
                    <MoneyLabel money={totals.upcoming} compact />
                </Pending>
            </div>
        </section>
    );
}
