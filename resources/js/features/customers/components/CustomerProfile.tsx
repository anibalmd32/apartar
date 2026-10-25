import { IconChip } from '@/components/ui/icon-chip';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import { cn } from '@/lib/utils';
import { type CustomerShowProps } from '@/types/customers';
import { type MoneyView } from '@/types/money';
import { cilCalendar, cilEnvelopeClosed, cilPhone, cilUser } from '@coreui/icons';
import { type ReactNode } from 'react';

function Contact({ icon, label, children }: { icon: string[]; label: string; children: ReactNode }) {
    return (
        <div className="flex min-w-0 items-center gap-3">
            <IconChip icon={icon} tone="neutral" />
            <dl className="min-w-0">
                <dt className="text-ink-3 text-xs">{label}</dt>
                <dd className="truncate text-sm font-semibold">{children}</dd>
            </dl>
        </div>
    );
}

export function ContactStrip({ customer }: Pick<CustomerShowProps, 'customer'>) {
    return (
        <section
            aria-label="Contacto"
            className="bg-base-100 border-line shadow-card grid gap-4 rounded-2xl border p-4 sm:grid-cols-2 xl:grid-cols-4"
        >
            <Contact icon={cilUser} label="Documento">
                {customer.document}
            </Contact>
            <Contact icon={cilPhone} label="Teléfono">
                <a href={`tel:${customer.phone}`} className="hover:underline">
                    {customer.phoneLabel}
                </a>
            </Contact>
            <Contact icon={cilEnvelopeClosed} label="Correo">
                {customer.email ?? 'Sin correo'}
            </Contact>
            <Contact icon={cilCalendar} label="Cliente desde">
                {customer.createdAt !== null ? formatDay(customer.createdAt) : '—'}
                {customer.termsAcceptedAt !== null ? (
                    <span className="text-ink-3 block text-xs font-normal">Términos aceptados el {formatDay(customer.termsAcceptedAt)}</span>
                ) : null}
            </Contact>
        </section>
    );
}

function Figure({ label, children, tone }: { label: string; children: ReactNode; tone?: 'success' | 'error' }) {
    return (
        <div className="bg-base-100 border-line shadow-card flex flex-col gap-1 rounded-2xl border px-4 py-3.5">
            <dt className="text-ink-3 text-xs">{label}</dt>
            <dd
                className={cn(
                    'text-xl leading-tight font-bold tracking-[-0.4px]',
                    tone === 'success' && 'text-success',
                    tone === 'error' && 'text-error',
                )}
            >
                {children}
            </dd>
        </div>
    );
}

const money = (value: MoneyView) => <MoneyLabel money={value} stacked />;

export function BalanceRow({ totals }: Pick<CustomerShowProps, 'totals'>) {
    const overdue = totals.overdueInstallments;

    return (
        <section aria-label="Balance">
            <dl className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                <Figure label="Comprometido">{money(totals.committed)}</Figure>
                <Figure label="Pagado" tone="success">
                    {money(totals.paid)}
                </Figure>
                <Figure label="Pendiente">{money(totals.remaining)}</Figure>
                <Figure label="Cuotas vencidas" tone={overdue > 0 ? 'error' : undefined}>
                    {overdue}
                    {overdue > 0 ? (
                        <span className="text-sm font-semibold">
                            {' '}
                            · <MoneyLabel money={totals.overdueAmount} />
                        </span>
                    ) : null}
                </Figure>
                <Figure label="Próximo vencimiento">{totals.nextDueDate !== null ? formatDay(totals.nextDueDate) : '—'}</Figure>
            </dl>
        </section>
    );
}
