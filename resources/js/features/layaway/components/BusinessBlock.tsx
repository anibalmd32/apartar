import { Avatar } from '@/components/shell/avatar';
import { PortalLayawayCard } from '@/features/layaway/components/PortalLayawayCard';
import { type BusinessBlock as BusinessBlockData } from '@/types/portal';
import { Link } from '@inertiajs/react';

interface BusinessBlockProps {
    block: BusinessBlockData;
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
                    {totals.nextDueDateLabel !== null ? <p className="text-ink-3 text-xs">Próximo vencimiento: {totals.nextDueDateLabel}</p> : null}
                </div>
                {closed ? null : (
                    <nav aria-label={`Enlaces de ${business.name}`} className="text-primary-ink flex shrink-0 gap-3 text-[13px] font-semibold">
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
