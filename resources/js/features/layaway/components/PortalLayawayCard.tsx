import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { PrimaryMoney } from '@/features/account/components/PrimaryMoney';
import { LAYAWAY_TONES } from '@/features/layaway/components/PortalLayawayHero';
import { cn } from '@/lib/utils';
import { type LayawaySummary } from '@/types/portal';
import { cilBasket, cilCalendar } from '@coreui/icons';
import { Link } from '@inertiajs/react';

function plural(count: number, one: string, many: string): string {
    return count === 1 ? one : many;
}

function nextStep(layaway: LayawaySummary): { text: string; urgent: boolean } | null {
    if (layaway.isTerminal) {
        return null;
    }

    if (layaway.overdueInstallments > 0) {
        const n = layaway.overdueInstallments;
        const days = layaway.overdueDays;
        const since = days ? ` · venció hace ${days} ${plural(days, 'día', 'días')}` : '';

        return { text: `${n} ${plural(n, 'cuota vencida', 'cuotas vencidas')}${since}`, urgent: true };
    }

    if (layaway.status === 'pending_down_payment') {
        return { text: 'Paga la inicial para activarlo', urgent: false };
    }

    if (layaway.nextDueDateLabel === null) {
        return null;
    }

    return { text: `Próxima: ${layaway.nextDueDateLabel}${layaway.nextAmount ? ` · ${layaway.nextAmount.usd}` : ''}`, urgent: false };
}

export function PortalLayawayCard({ layaway }: { layaway: LayawaySummary }) {
    const total = Number(layaway.total.cents);
    const progress = total > 0 ? Math.min(100, Math.max(0, (Number(layaway.paid.cents) / total) * 100)) : 0;
    const step = nextStep(layaway);

    return (
        <li>
            <Link
                href={route('portal.layaways.show', { code: layaway.code })}
                className="border-line bg-base-100 hover:border-line-strong flex h-full flex-col gap-3 rounded-2xl border p-4 transition-colors"
            >
                <span className="flex items-center gap-3">
                    {layaway.imageUrl ? (
                        <img src={layaway.imageUrl} alt="" loading="lazy" className="size-[52px] shrink-0 rounded-lg object-cover" />
                    ) : (
                        <span aria-hidden="true" className="bg-surface-2 text-ink-3 flex size-[52px] shrink-0 items-center justify-center rounded-lg">
                            <Icon icon={cilBasket} size={22} />
                        </span>
                    )}
                    <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span className="line-clamp-2 text-[15px] leading-snug font-semibold">
                            {layaway.quantity > 1 ? `${layaway.quantity} × ` : ''}
                            {layaway.productName}
                        </span>
                        <span className="text-ink-3 text-xs">{layaway.code}</span>
                    </span>
                    <Badge tone={LAYAWAY_TONES[layaway.status]} className="shrink-0">
                        {layaway.statusLabel}
                    </Badge>
                </span>

                <span className="bg-base-300 block h-2 overflow-hidden rounded-full" aria-hidden="true">
                    <span
                        className={cn('block h-full min-w-2 rounded-full', layaway.status === 'overdue' ? 'bg-error' : 'bg-success')}
                        style={{ width: `${progress}%` }}
                    />
                </span>

                <span className="flex items-baseline justify-between gap-2">
                    <span className="text-ink-3 text-xs">
                        Pagado <PrimaryMoney money={layaway.paid} /> de <PrimaryMoney money={layaway.total} />
                    </span>
                    <span className="text-[13px] font-bold whitespace-nowrap">
                        Resta <PrimaryMoney money={layaway.remaining} />
                    </span>
                </span>

                {step !== null ? (
                    <span
                        className={cn(
                            'flex items-center gap-2 rounded-[10px] py-2.5 pr-2.5 pl-3 text-[13px] font-semibold',
                            step.urgent ? 'bg-error-soft text-error' : 'bg-surface-2 text-ink-2',
                        )}
                    >
                        <Icon icon={cilCalendar} size={14} />
                        <span className="min-w-0 flex-1">{step.text}</span>
                        <span aria-hidden="true" className={cn('btn btn-sm', step.urgent ? 'btn-primary' : 'btn-secondary')}>
                            {step.urgent ? 'Pagar' : 'Ver'}
                        </span>
                    </span>
                ) : null}
            </Link>
        </li>
    );
}
