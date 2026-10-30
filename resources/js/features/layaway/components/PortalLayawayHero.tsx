import { Alert } from '@/components/ui/alert';
import { Badge, type BadgeTone } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { cn } from '@/lib/utils';
import { type LayawayStatus, type PortalLayawayShowProps } from '@/types/portal';
import { cilBasket } from '@coreui/icons';

export const LAYAWAY_TONES: Record<LayawayStatus, BadgeTone> = {
    pending_down_payment: 'warning',
    active: 'success',
    overdue: 'error',
    completed: 'info',
    cancelled: 'neutral',
    expired: 'neutral',
};

type Layaway = PortalLayawayShowProps['layaway'];

interface PortalLayawayHeroProps {
    layaway: Layaway;
    businessName: string;
    className?: string;
}

export function PortalLayawayHero({ layaway, businessName, className }: PortalLayawayHeroProps) {
    const total = Number(layaway.total.cents);
    const progress = total > 0 ? Math.min(100, (Number(layaway.paid.cents) / total) * 100) : 0;

    return (
        <section className={cn('border-line bg-base-100 shadow-card flex flex-col gap-4 rounded-2xl border p-5', className)}>
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div className="flex min-w-0 flex-1 items-center gap-3">
                    <span aria-hidden="true" className="bg-surface-2 text-ink-3 flex size-16 shrink-0 items-center justify-center rounded-xl">
                        <Icon icon={cilBasket} size={26} />
                    </span>
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <h1 className="text-[18px] leading-snug font-bold">
                            {layaway.quantity > 1 ? `${layaway.quantity} × ` : ''}
                            {layaway.productName}
                        </h1>
                        <p className="text-ink-3 text-[13px]">
                            {layaway.code} · <span>Apartado en {businessName}</span>
                        </p>
                    </div>
                </div>
                <Badge tone={LAYAWAY_TONES[layaway.status]} className="shrink-0 self-start sm:self-center">
                    {layaway.statusLabel}
                </Badge>
            </div>

            <dl className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                <Amount label="Total" money={layaway.total} />
                <Amount label="Inicial" money={layaway.downPayment} zeroLabel="Sin inicial" />
                <Amount label="Pagado" money={layaway.paid} className="text-success" />
                <Amount label="Te falta" money={layaway.remaining} />
            </dl>

            <span className="bg-base-300 block h-2 overflow-hidden rounded-full" aria-hidden="true">
                <span
                    className={cn('block h-full rounded-full', layaway.status === 'overdue' ? 'bg-error' : 'bg-success')}
                    style={{ width: `${progress}%` }}
                />
            </span>

            {layaway.statusMessage !== null ? <Alert tone="info">{layaway.statusMessage}</Alert> : null}
        </section>
    );
}

function Amount({ label, money, className, zeroLabel }: { label: string; money: Layaway['total']; className?: string; zeroLabel?: string }) {
    return (
        <div className="bg-surface-2 flex flex-col gap-0.5 rounded-xl px-3 py-2.5">
            <dt className="text-ink-3 text-xs">{label}</dt>
            <dd className={cn('text-[17px] leading-tight font-extrabold', className)}>
                {zeroLabel !== undefined && Number(money.cents) === 0 ? zeroLabel : <MoneyLabel money={money} stacked />}
            </dd>
        </div>
    );
}
