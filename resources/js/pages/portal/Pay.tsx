import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { LAYAWAY_TONES } from '@/features/layaway/components/PortalLayawayHero';
import PortalLayout from '@/layouts/portal-layout';
import { type PayableLayaway, type PortalPayProps, type PortalSessionView } from '@/types/portal';
import { cilArrowRight, cilBasket } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';

export default function Pay({ layaways, dashboardUrl }: PortalPayProps) {
    const { auth } = usePage<{ auth: { portal: PortalSessionView | null } }>().props;
    const businesses = new Set(layaways.map((layaway) => layaway.businessName));
    const businessName = businesses.size === 1 ? ([...businesses][0] ?? 'Mi cuenta') : 'Mi cuenta';

    return (
        <PortalLayout title="¿Qué vas a pagar?" businessName={businessName} customerName={auth.portal?.fullName ?? ''}>
            <div className="mx-auto flex w-full max-w-[640px] flex-col gap-4 lg:gap-6">
                <header className="flex flex-col gap-1.5 text-center">
                    <h1 className="text-[24px] leading-tight font-extrabold tracking-[-0.5px] lg:text-[32px]">¿Qué vas a pagar?</h1>
                    <p className="text-ink-2 text-[15px]">Elige el apartado. Arriba está el más reciente.</p>
                </header>

                {layaways.length === 0 ? (
                    <div className="bg-base-100 border-line flex flex-col items-center gap-3 rounded-2xl border px-6 py-10 text-center">
                        <p className="text-ink-2 text-sm">No tienes apartados por pagar ahora mismo.</p>
                        <Link href={dashboardUrl} className="btn">
                            Ver mis apartados
                        </Link>
                    </div>
                ) : (
                    <ul className="flex flex-col gap-3" aria-label="Apartados por pagar">
                        {layaways.map((layaway) => (
                            <PayableRow key={layaway.code} layaway={layaway} />
                        ))}
                    </ul>
                )}

                {layaways.length > 0 ? (
                    <Link href={dashboardUrl} className="text-ink-3 hover:text-base-content self-center text-[13px] font-semibold">
                        Ver todos mis apartados
                    </Link>
                ) : null}
            </div>
        </PortalLayout>
    );
}

function PayableRow({ layaway }: { layaway: PayableLayaway }) {
    const pending = layaway.status === 'pending_down_payment';

    return (
        <li>
            <Link
                href={layaway.payUrl}
                className="border-line bg-base-100 hover:border-line-strong flex items-center gap-3 rounded-2xl border p-4 transition-colors"
                aria-label={`Pagar ${layaway.productName} (${layaway.code})`}
            >
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
                    <span className="text-ink-3 text-xs">
                        {layaway.code} · {layaway.businessName}
                    </span>
                    {layaway.nextAmount ? (
                        <span className="text-ink-2 text-xs">
                            {pending ? 'Inicial' : 'Próximo pago'}: <MoneyLabel money={layaway.nextAmount} className="font-semibold" />
                        </span>
                    ) : null}
                </span>
                <span className="flex shrink-0 flex-col items-end gap-2">
                    <Badge tone={LAYAWAY_TONES[layaway.status]}>{layaway.statusLabel}</Badge>
                    <span aria-hidden="true" className="btn btn-primary btn-sm">
                        Pagar
                        <Icon icon={cilArrowRight} size={14} />
                    </span>
                </span>
            </Link>
        </li>
    );
}
