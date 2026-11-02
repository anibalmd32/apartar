import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { paidOnly } from '@/features/layaway/lib/paid-money';
import { INSTALLMENT_TONES } from '@/lib/status-tones';
import { cn } from '@/lib/utils';
import { type InstallmentRow, type InstallmentTone } from '@/types/portal';
import { cilCheckAlt } from '@coreui/icons';

const CHIPS: Record<InstallmentTone, string> = {
    paid: 'bg-success-soft text-success',
    overdue: 'bg-error-soft text-error',
    soon: 'bg-warning-soft text-warning',
    pending: 'bg-surface-2 text-ink-2',
};

function reviewCovers(row: InstallmentRow): boolean {
    return row.tone !== 'paid' && row.inReview != null && Number(row.inReview.cents) >= Number(row.pending.cents);
}

function dueText(row: InstallmentRow): string {
    if (row.tone === 'paid') {
        return 'Pagada';
    }

    if (reviewCovers(row)) {
        return 'En revisión';
    }

    if (row.daysUntilDue < 0) {
        const days = -row.daysUntilDue;

        return days === 1 ? 'Venció ayer' : `Venció hace ${days} días`;
    }

    if (row.daysUntilDue === 0) {
        return 'Vence hoy';
    }

    return row.daysUntilDue === 1 ? 'Vence mañana' : `Vence en ${row.daysUntilDue} días`;
}

interface ScheduleTableProps {
    installments: readonly InstallmentRow[];
}

export function ScheduleTable({ installments }: ScheduleTableProps) {
    return (
        <ol className="divide-line flex flex-col divide-y" aria-label="Cronograma de cuotas">
            {installments.map((row) => {
                return (
                    <li
                        key={row.number}
                        className="grid grid-cols-[34px_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 py-3 sm:grid-cols-[34px_minmax(0,1fr)_auto_auto]"
                    >
                        <span
                            aria-hidden="true"
                            className={cn(
                                'row-span-2 flex size-[34px] items-center justify-center rounded-full text-[13px] font-bold sm:row-span-1',
                                reviewCovers(row) ? 'bg-info-soft text-info' : CHIPS[row.tone],
                            )}
                        >
                            {row.tone === 'paid' ? <Icon icon={cilCheckAlt} size={14} /> : row.number}
                        </span>
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <span className="text-[15px] font-semibold">Cuota {row.number}</span>
                            <span className={cn('text-xs', row.tone === 'overdue' ? 'text-error' : 'text-ink-3')}>
                                {row.daysUntilDue < 0 ? 'Venció' : 'Vence'} el {row.dueDateLabel}
                            </span>
                            {row.status === 'partially_paid' || (row.paid.cents > 0 && row.tone !== 'paid') ? (
                                <span className="text-ink-3 text-xs">
                                    pagado <MoneyLabel money={paidOnly(row.paid)} />, falta <MoneyLabel money={row.pending} />
                                </span>
                            ) : null}
                            {row.inReview != null && row.tone !== 'paid' && !reviewCovers(row) ? (
                                <span className="text-info text-xs">
                                    en revisión <MoneyLabel money={row.inReview} />
                                </span>
                            ) : null}
                        </div>
                        <span className="row-span-2 text-right text-[15px] font-bold sm:row-span-1">
                            <MoneyLabel money={row.amount} stacked />
                        </span>
                        <Badge
                            tone={reviewCovers(row) ? 'info' : INSTALLMENT_TONES[row.tone]}
                            className="col-start-2 row-start-2 justify-self-start sm:col-start-4 sm:row-start-1"
                        >
                            {dueText(row)}
                        </Badge>
                    </li>
                );
            })}
        </ol>
    );
}
