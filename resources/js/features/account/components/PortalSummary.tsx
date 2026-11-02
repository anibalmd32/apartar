import { Icon } from '@/components/ui/icon';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { paidOnly } from '@/features/layaway/lib/paid-money';
import { cn } from '@/lib/utils';
import { type MoneyView } from '@/types/money';
import { type PortalTotals } from '@/types/portal';
import { cilClock } from '@coreui/icons';
import { Link } from '@inertiajs/react';

interface PortalSummaryProps {
    totals: PortalTotals;
    label: string;
    payUrl: string;
    today: string;
}

type Due = { kind: 'due'; amount: MoneyView; when: string; overdue: boolean } | { kind: 'review' } | null;

function plural(count: number, one: string, many: string): string {
    return count === 1 ? one : many;
}

export function dueOf(totals: PortalTotals, today: string): Due {
    const cents = (money?: MoneyView) => Number(money?.cents ?? 0);
    const label = totals.nextDueDateLabel;

    if (totals.overdueInstallments > 0 && totals.catchUp !== undefined) {
        if (cents(totals.catchUp) === 0) {
            return { kind: 'review' };
        }

        const count = `${totals.overdueInstallments} ${plural(totals.overdueInstallments, 'cuota vencida', 'cuotas vencidas')}`;

        return { kind: 'due', amount: totals.catchUp, when: label !== null ? `Venció el ${label} · ${count}` : count, overdue: true };
    }

    if (label !== null && totals.nextDueDate !== null && cents(totals.nextAmount) > 0 && totals.nextAmount !== undefined) {
        const past = totals.nextDueDate < today;

        return { kind: 'due', amount: totals.nextAmount, when: `${past ? 'Venció' : 'Vence'} el ${label}`, overdue: past };
    }

    return totals.paymentInReview ? { kind: 'review' } : null;
}

function Detail({ label, money, className }: { label: string; money: MoneyView; className?: string }) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5">
            <dt className="text-ink-3 text-xs">{label}</dt>
            <dd className={cn('text-sm font-bold tabular-nums', className)}>
                <MoneyLabel money={money} compact />
            </dd>
        </div>
    );
}

function Headline({ due, payUrl }: { due: Due; payUrl: string }) {
    if (due === null) {
        return null;
    }

    if (due.kind === 'review') {
        return (
            <p className="bg-info-soft flex items-start gap-2.5 rounded-xl px-3.5 py-3 text-sm">
                <Icon icon={cilClock} size={16} className="text-info mt-0.5 shrink-0" />
                <span>
                    <strong className="font-semibold">Tu pago está en revisión.</strong> Cuando lo aprueben verás aquí lo que sigue.
                </span>
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div className="flex min-w-0 flex-col gap-1">
                <p className="tracking-heading text-[26px] leading-tight font-extrabold lg:text-[30px]">
                    Debes <MoneyLabel money={due.amount} secondaryClassName="text-base font-semibold" />
                </p>
                <p className={cn('text-sm', due.overdue ? 'text-error font-semibold' : 'text-ink-2')}>{due.when}</p>
            </div>
            <Link href={payUrl} className="btn btn-primary btn-lg max-sm:btn-block sm:min-w-40">
                Pagar
            </Link>
        </div>
    );
}

export function PortalSummary({ totals, label, payUrl, today }: PortalSummaryProps) {
    const due = dueOf(totals, today);

    return (
        <section
            aria-label={label}
            className={cn(
                'bg-base-100 shadow-card flex flex-col gap-4 rounded-2xl border p-4 lg:p-6',
                due?.kind === 'due' && due.overdue ? 'border-error/50' : 'border-line',
            )}
        >
            <Headline due={due} payUrl={payUrl} />

            <dl className={cn('grid grid-cols-3 gap-3', due !== null && 'border-line border-t pt-3')}>
                <Detail label="Comprometido" money={totals.committed} />
                <Detail label="Pagado" money={paidOnly(totals.paid)} className="text-success" />
                <Detail label="Restante" money={totals.remaining} />
            </dl>
            <p className="text-ink-3 -mt-1 text-xs">
                {totals.activeLayaways} {plural(totals.activeLayaways, 'apartado activo', 'apartados activos')}
            </p>
        </section>
    );
}
