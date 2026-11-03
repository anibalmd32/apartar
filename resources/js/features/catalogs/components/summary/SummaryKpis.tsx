import { StatCard } from '@/components/ui/stat-card';
import { AmountProgress } from '@/features/collections/components/AmountProgress';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type CatalogSummaryProps } from '@/types/catalog-summary';
import { cilCalendar, cilCash, cilCheckCircle, cilWarning } from '@coreui/icons';

export function SummaryKpis({ totals }: { totals: CatalogSummaryProps['totals'] }) {
    const plural = (count: number, one: string, many: string) => `${count} ${count === 1 ? one : many}`;

    return (
        <section aria-label="Resumen de ventas" className="flex flex-col gap-3">
            <div className="bg-base-100 border-line flex flex-col gap-3 rounded-[14px] border p-4 md:p-5">
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
                <AmountProgress paid={totals.paid} total={totals.committed} progress={totals.progress} />
            </div>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard label="Cobrado" icon={cilCash} tone="success" value={<MoneyLabel money={totals.paid} compact />} />
                <StatCard label="Vencido sin pagar" icon={cilWarning} tone="error" value={<MoneyLabel money={totals.overdue} compact />}>
                    {plural(totals.overdueLayaways, 'apartado en mora', 'apartados en mora')}
                </StatCard>
                <StatCard label="Vence en 7 días" icon={cilCalendar} tone="warning" value={<MoneyLabel money={totals.upcoming} compact />} />
                <StatCard label="Entregados" icon={cilCheckCircle} tone="primary" value={totals.delivered}>
                    {totals.awaitingDelivery > 0 ? plural(totals.awaitingDelivery, 'pagado sin entregar', 'pagados sin entregar') : null}
                </StatCard>
            </div>
        </section>
    );
}
