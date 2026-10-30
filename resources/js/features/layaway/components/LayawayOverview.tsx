import { Badge, type BadgeTone } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { SectionCard } from '@/components/ui/section-card';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { RescheduleInstallmentDialog } from '@/features/layaway/components/RescheduleInstallmentDialog';
import { cn } from '@/lib/utils';
import { type BackofficeInstallmentRow, type BackofficeLayaway } from '@/types/layaway-backoffice';
import { cilBasket, cilCheckAlt } from '@coreui/icons';

type Tone = 'paid' | 'overdue' | 'pending';

function toneOf(installment: BackofficeInstallmentRow, today: string): Tone {
    if (Number(installment.paid.cents) >= Number(installment.amount.cents)) {
        return 'paid';
    }

    return installment.dueDate < today ? 'overdue' : 'pending';
}

const BADGE: Record<Tone, BadgeTone> = { paid: 'success', overdue: 'error', pending: 'neutral' };

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

function Figure({ label, money, tone, zeroLabel }: { label: string; money: BackofficeLayaway['total']; tone?: 'success'; zeroLabel?: string }) {
    return (
        <div className="bg-surface-2 flex flex-col gap-1 rounded-[10px] p-3">
            <dt className="text-ink-3 text-xs">{label}</dt>
            <dd className={cn('text-xl leading-tight font-bold tracking-[-0.4px]', tone === 'success' && 'text-success')}>
                {zeroLabel !== undefined && Number(money.cents) === 0 ? zeroLabel : <MoneyLabel money={money} stacked />}
            </dd>
        </div>
    );
}

export function LayawaySummary({ layaway, installments }: { layaway: BackofficeLayaway; installments: readonly BackofficeInstallmentRow[] }) {
    const total = Number(layaway.total.cents);
    const percent = total > 0 ? Math.min(100, Math.round((Number(layaway.paid.cents) / total) * 100)) : 0;
    const today = todayIso();
    const next = installments.find((installment) => toneOf(installment, today) !== 'paid');

    return (
        <section aria-label="Resumen del apartado" className="bg-base-100 border-line shadow-card flex flex-col gap-[18px] rounded-2xl border p-5">
            <div className="flex items-center gap-3.5">
                <IconChip icon={cilBasket} tone="neutral" size={56} className="rounded-xl" />
                <div className="min-w-0">
                    <p className="truncate text-[17px] font-bold">{layaway.productName}</p>
                    <p className="text-ink-3 text-xs">
                        {layaway.quantity} {layaway.quantity === 1 ? 'unidad' : 'unidades'}
                    </p>
                </div>
            </div>

            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Figure label="Total" money={layaway.total} />
                <Figure label="Inicial" money={layaway.downPayment} zeroLabel="Sin inicial" />
                <Figure label="Pagado" money={layaway.paid} tone="success" />
                <Figure label="Pendiente" money={layaway.remaining} />
            </dl>

            {layaway.downPaymentProgress !== null ? (
                <p className="bg-warning-soft text-ink-2 rounded-[10px] px-3 py-2 text-sm">
                    Inicial abonada: <MoneyLabel money={layaway.downPaymentProgress.paid} className="font-semibold" /> · faltan{' '}
                    <MoneyLabel money={layaway.downPaymentProgress.missing} className="font-semibold" /> para activar el apartado.
                </p>
            ) : null}

            <div className="flex flex-col gap-1.5">
                <span className="bg-base-300 block h-1.5 overflow-hidden rounded-full" aria-hidden="true">
                    <span className="bg-success block h-full rounded-full" style={{ width: `${percent}%` }} />
                </span>
                <p className="flex flex-wrap justify-between gap-2 text-xs">
                    <span className="font-semibold">{percent}% pagado</span>
                    {next !== undefined ? (
                        <span className={toneOf(next, today) === 'overdue' ? 'text-error font-semibold' : 'text-ink-3'}>
                            Próxima: cuota {next.number} · {next.dueDateLabel}
                        </span>
                    ) : null}
                </p>
            </div>
        </section>
    );
}

export function InstallmentPlan({ installments }: { installments: readonly BackofficeInstallmentRow[] }) {
    const today = todayIso();

    return (
        <SectionCard
            title="Plan de pagos"
            subtitle={`${installments.length} ${installments.length === 1 ? 'cuota' : 'cuotas'}`}
            aria-label="Cronograma"
        >
            <ol className="divide-line -mt-1 flex flex-col divide-y">
                {installments.map((installment) => {
                    const tone = toneOf(installment, today);

                    return (
                        <li key={installment.number} className="flex flex-wrap items-center gap-3 py-3">
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'flex size-8 shrink-0 items-center justify-center rounded-full text-[13px] font-bold',
                                    tone === 'paid' && 'bg-success-soft text-success',
                                    tone === 'overdue' && 'bg-error-soft text-error',
                                    tone === 'pending' && 'bg-surface-2 text-ink-2',
                                )}
                            >
                                {tone === 'paid' ? <Icon icon={cilCheckAlt} size={14} /> : installment.number}
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col">
                                <span className="text-sm font-semibold">Cuota {installment.number}</span>
                                <span className={cn('text-xs', tone === 'overdue' ? 'text-error' : 'text-ink-3')}>{installment.dueDateLabel}</span>
                            </span>
                            <span className="flex flex-col items-end text-right">
                                <span className="text-sm font-bold">
                                    <MoneyLabel money={installment.amount} />
                                </span>
                                <span className="text-ink-3 text-xs">
                                    Pagado <MoneyLabel money={installment.paid} />
                                </span>
                            </span>
                            <span className="flex w-full items-center justify-end gap-1 sm:w-auto sm:min-w-56">
                                <Badge tone={BADGE[tone]}>{installment.statusLabel}</Badge>
                                {installment.reschedule !== null ? (
                                    <RescheduleInstallmentDialog installment={installment} option={installment.reschedule} />
                                ) : null}
                            </span>
                        </li>
                    );
                })}
            </ol>
        </SectionCard>
    );
}
