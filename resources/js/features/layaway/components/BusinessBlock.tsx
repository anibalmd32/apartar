import { Avatar } from '@/components/shell/avatar';
import { RateStamp } from '@/components/shell/rate-pill';
import { PortalLayawayCard } from '@/features/layaway/components/PortalLayawayCard';
import { type BusinessBlock as BusinessBlockData, type PortalTotals } from '@/types/portal';
import { Link } from '@inertiajs/react';

interface BusinessBlockProps {
    block: BusinessBlockData;
}

function DueStamp({ totals }: { totals: PortalTotals }) {
    if (totals.nextDueDateLabel === null) {
        return totals.paymentInReview ? <span>Pago en revisión</span> : null;
    }

    if (totals.overdueInstallments === 0 || totals.catchUp === undefined) {
        return <span>Próximo vencimiento: {totals.nextDueDateLabel}</span>;
    }

    return Number(totals.catchUp.cents) > 0 ? (
        <span className="text-error font-semibold">Vencido desde el {totals.nextDueDateLabel}</span>
    ) : (
        <span>Pago en revisión</span>
    );
}

export function BusinessBlock({ block }: BusinessBlockProps) {
    const { business, totals, layaways, history, closed } = block;

    return (
        <section className="flex flex-col gap-3 lg:gap-4" aria-label={`Tus apartados en ${business.name}`}>
            <header className="flex items-center gap-2.5">
                {business.brandingEnabled && business.logoUrl !== null ? (
                    <img src={business.logoUrl} alt="" className="size-8 shrink-0 rounded-full object-contain" />
                ) : (
                    <Avatar name={business.name} size={32} tone="accent" />
                )}
                <div className="min-w-0 flex-1">
                    <h2 className="truncate text-[17px] font-bold">{business.name}</h2>
                    <p className="text-ink-3 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs">
                        <DueStamp totals={totals} />
                        <RateStamp rate={block.rate} />
                    </p>
                </div>
                {closed ? null : (
                    <nav
                        aria-label={`Enlaces de ${business.name}`}
                        className="hit-area-children text-primary-ink flex shrink-0 gap-3 text-[13px] font-semibold"
                    >
                        <Link href={`/mi-cuenta/chat/${business.slug}`} className="hover:underline">
                            Chat
                        </Link>
                        <a href={`/${business.slug}`} className="hover:underline">
                            Catálogos
                        </a>
                    </nav>
                )}
            </header>

            {closed ? (
                <p role="note" className="bg-surface-2 text-ink-2 rounded-xl px-4 py-3 text-sm">
                    {business.name} ya no opera en Apartar. Tus apartados se conservan aquí para que los consultes.
                </p>
            ) : null}

            {layaways.length > 0 ? (
                <ul className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {layaways.map((layaway) => (
                        <PortalLayawayCard key={layaway.code} layaway={layaway} />
                    ))}
                </ul>
            ) : null}

            {history.length > 0 ? (
                <details open={layaways.length === 0}>
                    <summary className="text-ink-2 hover:text-base-content cursor-pointer py-1 text-sm font-semibold">
                        Historial ({history.length})
                    </summary>
                    <ul className="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                        {history.map((layaway) => (
                            <PortalLayawayCard key={layaway.code} layaway={layaway} />
                        ))}
                    </ul>
                </details>
            ) : null}
        </section>
    );
}
