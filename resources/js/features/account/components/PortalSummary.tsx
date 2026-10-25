import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { cn } from '@/lib/utils';
import { type PortalTotals } from '@/types/portal';
import { type ReactNode } from 'react';

interface PortalSummaryProps {
    totals: PortalTotals;
    label: string;
}

function Figure({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
    return (
        <div className="flex min-w-0 flex-col gap-1">
            <dt className="text-ink-3 text-xs">{label}</dt>
            <dd className={cn('text-[18px] leading-tight font-extrabold tracking-[-0.3px] lg:text-[22px]', className)}>{children}</dd>
        </div>
    );
}

export function PortalSummary({ totals, label }: PortalSummaryProps) {
    return (
        <section aria-label={label} className="border-line bg-base-100 shadow-card rounded-2xl border p-4 lg:p-6">
            <dl className="grid grid-cols-2 gap-x-4 gap-y-4 lg:grid-cols-4">
                <Figure label="Comprometido">
                    <MoneyLabel money={totals.committed} stacked />
                </Figure>
                <Figure label="Pagado" className="text-success">
                    <MoneyLabel money={totals.paid} stacked />
                </Figure>
                <Figure label="Restante">
                    <MoneyLabel money={totals.remaining} stacked />
                </Figure>
                <Figure label="Próximo pago">{totals.nextDueDateLabel ?? '—'}</Figure>
            </dl>
            <p className="text-ink-3 mt-3 text-xs">
                {totals.activeLayaways} {totals.activeLayaways === 1 ? 'apartado activo' : 'apartados activos'}
                {totals.overdueInstallments > 0
                    ? ` · ${totals.overdueInstallments} ${totals.overdueInstallments === 1 ? 'cuota vencida' : 'cuotas vencidas'}`
                    : ''}
            </p>
        </section>
    );
}
