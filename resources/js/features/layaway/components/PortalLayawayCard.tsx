import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { PrimaryMoney } from '@/features/account/components/PrimaryMoney';
import { paidOnly } from '@/features/layaway/lib/paid-money';
import { todayIn } from '@/features/layaway/lib/today';
import { LAYAWAY_TONES } from '@/lib/status-tones';
import { cn } from '@/lib/utils';
import { type LayawaySummary } from '@/types/portal';
import { cilBasket, cilCalendar, cilClock } from '@coreui/icons';
import { Link } from '@inertiajs/react';

function plural(count: number, one: string, many: string): string {
    return count === 1 ? one : many;
}

type StepTone = 'urgent' | 'review' | 'calm';

interface Step {
    text: string;
    detail?: string;
    tone: StepTone;
}

const STEP_TONES: Record<StepTone, string> = {
    urgent: 'bg-error-soft text-error',
    review: 'bg-info-soft text-base-content',
    calm: 'bg-surface-2 text-ink-2',
};

function inReviewDetail(inReview: NonNullable<LayawaySummary['suggestion']>['inReview']): string | undefined {
    return inReview !== null ? `${inReview.amount.usd} en revisión` : undefined;
}

function nextStep(layaway: LayawaySummary, today: string): Step | null {
    const suggestion = layaway.suggestion ?? null;

    if (layaway.isTerminal) {
        return null;
    }

    if (suggestion?.covered && suggestion.inReview !== null) {
        const on = suggestion.inReview.submittedOn !== null ? ` el ${suggestion.inReview.submittedOn}` : '';

        return { text: 'Tu pago está en revisión', detail: `Enviaste ${suggestion.inReview.amount.usd}${on}`, tone: 'review' };
    }

    if (layaway.overdueInstallments > 0) {
        const n = layaway.overdueInstallments;
        const days = layaway.overdueDays;
        const overdue = `${n} ${plural(n, 'cuota vencida', 'cuotas vencidas')}${days ? ` · venció hace ${days} ${plural(days, 'día', 'días')}` : ''}`;

        return suggestion?.kind === 'overdue'
            ? { text: `Ponte al día: ${suggestion.amount.usd}`, detail: overdue, tone: 'urgent' }
            : { text: overdue, tone: 'urgent' };
    }

    if (layaway.status === 'pending_down_payment') {
        return { text: 'Paga la inicial para activarlo', detail: inReviewDetail(suggestion?.inReview ?? null), tone: 'calm' };
    }

    if (layaway.nextDueDateLabel === null) {
        return null;
    }

    const amount = suggestion?.kind === 'installment' ? suggestion.amount : layaway.nextAmount;

    const past = layaway.nextDueDate !== null && layaway.nextDueDate < today;

    return {
        text: `${past ? 'Venció el' : 'Próxima:'} ${layaway.nextDueDateLabel}${amount ? ` · ${amount.usd}` : ''}`,
        detail: inReviewDetail(suggestion?.inReview ?? null),
        tone: past ? 'urgent' : 'calm',
    };
}

interface PortalLayawayCardProps {
    layaway: LayawaySummary;
    showStep?: boolean;
    today?: string;
}

export function PortalLayawayCard({ layaway, showStep = true, today = todayIn() }: PortalLayawayCardProps) {
    const total = Number(layaway.total.cents);
    const progress = total > 0 ? Math.min(100, Math.max(0, (Number(layaway.paid.cents) / total) * 100)) : 0;
    const step = showStep ? nextStep(layaway, today) : null;

    return (
        <li>
            <Link
                href={route('portal.layaways.show', { code: layaway.code })}
                className="border-line bg-base-100 hover:border-line-strong flex h-full flex-col gap-3 rounded-2xl border p-4 transition-colors"
            >
                <span className="flex items-start gap-3 sm:items-center">
                    {layaway.imageUrl ? (
                        <img
                            src={layaway.imageUrl}
                            alt=""
                            loading="lazy"
                            className="border-line size-[52px] shrink-0 rounded-lg border object-cover"
                        />
                    ) : (
                        <span
                            aria-hidden="true"
                            className="border-line bg-surface-2 text-ink-3 flex size-[52px] shrink-0 items-center justify-center rounded-lg border"
                        >
                            <Icon icon={cilBasket} size={22} />
                        </span>
                    )}
                    <span className="flex min-w-0 flex-1 flex-col gap-1.5 sm:flex-row sm:items-center sm:gap-3">
                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span className="line-clamp-2 text-[15px] leading-snug font-semibold">
                                {layaway.quantity > 1 ? `${layaway.quantity} × ` : ''}
                                {layaway.productName}
                            </span>
                            <span className="text-ink-3 text-xs whitespace-nowrap">{layaway.code}</span>
                        </span>
                        <Badge tone={LAYAWAY_TONES[layaway.status]} className="shrink-0 self-start sm:self-center">
                            {layaway.statusLabel}
                        </Badge>
                    </span>
                </span>

                <span className="bg-base-300 block h-2 overflow-hidden rounded-full" aria-hidden="true">
                    <span
                        className={cn('block h-full min-w-2 rounded-full', layaway.status === 'overdue' ? 'bg-error' : 'bg-success')}
                        style={{ width: `${progress}%` }}
                    />
                </span>

                <span className="flex items-baseline justify-between gap-2">
                    <span className="text-ink-3 text-xs">
                        Pagado <PrimaryMoney money={paidOnly(layaway.paid)} /> de <PrimaryMoney money={layaway.total} />
                    </span>
                    <span className="text-[13px] font-bold whitespace-nowrap">
                        Resta <PrimaryMoney money={layaway.remaining} />
                    </span>
                </span>

                {step !== null ? (
                    <span
                        className={cn('flex items-center gap-2 rounded-[10px] py-2.5 pr-2.5 pl-3 text-[13px] font-semibold', STEP_TONES[step.tone])}
                    >
                        <Icon
                            icon={step.tone === 'review' ? cilClock : cilCalendar}
                            size={14}
                            className={cn(step.tone === 'review' && 'text-info')}
                        />
                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span>{step.text}</span>
                            {step.detail !== undefined ? <span className="text-xs font-normal opacity-80">{step.detail}</span> : null}
                        </span>
                        <span aria-hidden="true" className={cn('btn btn-sm', step.tone === 'urgent' ? 'btn-primary' : 'btn-secondary')}>
                            {step.tone === 'urgent' ? 'Pagar' : 'Ver'}
                        </span>
                    </span>
                ) : null}
            </Link>
        </li>
    );
}
