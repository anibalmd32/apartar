import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type CollectionIndexProps } from '@/types/collections';
import { type ReactNode } from 'react';

export function CollectionSummary({ summary }: { summary: NonNullable<CollectionIndexProps['summary']> }) {
    return (
        <dl aria-label="Resumen" className="bg-primary-soft flex flex-wrap items-baseline gap-x-6 gap-y-2 rounded-xl px-[18px] py-3.5">
            <Figure label={summary.installments === 1 ? 'Cuota' : 'Cuotas'}>{summary.installments}</Figure>
            <Figure label="Por cobrar">
                <MoneyLabel money={summary.pending} />
            </Figure>
            <Figure label={summary.customers === 1 ? 'Cliente' : 'Clientes'}>{summary.customers}</Figure>
        </dl>
    );
}

function Figure({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-baseline gap-1.5">
            <dd className="text-base font-bold">{children}</dd>
            <dt className="text-ink-2 order-last text-xs">{label}</dt>
        </div>
    );
}
