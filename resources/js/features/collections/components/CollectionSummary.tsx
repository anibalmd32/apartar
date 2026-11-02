import { Icon } from '@/components/ui/icon';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { bo } from '@/lib/surfaces';
import { type CollectionIndexProps } from '@/types/collections';
import { cilChevronRight } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

export function CollectionSummary({ summary }: { summary: NonNullable<CollectionIndexProps['summary']> }) {
    return (
        <dl
            aria-label="Resumen"
            className="bg-primary-soft flex flex-wrap items-baseline gap-x-6 gap-y-2 rounded-xl px-[18px] py-3.5 max-lg:gap-x-4 max-lg:gap-y-0.5 max-lg:rounded-none max-lg:bg-transparent max-lg:p-0"
        >
            <Figure label={summary.installments === 1 ? 'Cuota' : 'Cuotas'}>{summary.installments}</Figure>
            <Figure label="Por cobrar">
                <MoneyLabel money={summary.pending} secondaryClassName="max-lg:hidden" />
            </Figure>
            <Figure label={summary.customers === 1 ? 'Cliente' : 'Clientes'}>{summary.customers}</Figure>
        </dl>
    );
}

function Figure({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-baseline gap-1.5 whitespace-nowrap max-lg:gap-1">
            <dd className="text-base font-bold max-lg:text-sm">{children}</dd>
            <dt className="text-ink-2 order-last text-xs max-lg:text-sm max-lg:lowercase">{label}</dt>
        </div>
    );
}

export function AwaitingDownPaymentLink({ awaiting }: { awaiting: NonNullable<CollectionIndexProps['awaitingDownPayment']> }) {
    return (
        <Link
            href={bo('/cobranza?preset=awaiting_down_payment')}
            className="bg-warning-soft hover:border-line-strong hit-area flex flex-wrap items-center justify-between gap-2 rounded-xl border border-transparent px-[18px] py-3 text-sm max-lg:min-h-10 max-lg:flex-nowrap max-lg:rounded-lg max-lg:px-3 max-lg:py-2"
        >
            <span className="min-w-0 max-lg:truncate">
                <strong>{awaiting.count === 1 ? '1 apartado espera la inicial' : `${awaiting.count} apartados esperan la inicial`}</strong>{' '}
                <span className="max-lg:sr-only">
                    {'· '}
                    <MoneyLabel money={awaiting.missing} /> por cobrar
                </span>
            </span>
            <span className="text-primary-ink inline-flex shrink-0 items-center gap-1 font-semibold">
                Ver cuáles
                <Icon icon={cilChevronRight} size={14} />
            </span>
        </Link>
    );
}
