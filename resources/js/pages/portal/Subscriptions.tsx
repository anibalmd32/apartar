import { Avatar } from '@/components/shell/avatar';
import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import PortalLayout from '@/layouts/portal-layout';
import { cn } from '@/lib/utils';
import { type SubscriptionRow, type SubscriptionsPageProps } from '@/types/account';
import { cilLibrary } from '@coreui/icons';
import { router } from '@inertiajs/react';

export default function Subscriptions({ account, subscriptions }: SubscriptionsPageProps) {
    return (
        <PortalLayout title="Catálogos que sigues" businessName="Mi cuenta" customerName={account.displayName}>
            <header className="flex flex-col gap-1">
                <h1 className="text-[24px] leading-tight font-extrabold tracking-[-0.6px] lg:text-[30px]">Catálogos que sigo</h1>
                <p className="text-ink-2 max-w-[760px] text-sm leading-normal">
                    Te avisamos cuando agreguen productos. Silencia un catálogo para dejar de recibir avisos sin dejar de seguirlo.
                </p>
            </header>

            {subscriptions.length === 0 ? (
                <div className="border-line bg-base-100 rounded-2xl border p-8 text-center">
                    <p className="font-bold">Todavía no sigues ningún catálogo.</p>
                    <p className="text-ink-2 mt-2 text-sm">Cuando abras uno que te compartan, pulsa «Seguir este catálogo» y aparecerá aquí.</p>
                </div>
            ) : (
                <ul className="flex flex-col gap-3">
                    {subscriptions.map((row) => (
                        <li key={row.catalogId}>
                            <SubscriptionCard row={row} />
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}

function SubscriptionCard({ row }: { row: SubscriptionRow }) {
    const toggleMute = () =>
        router.patch(route('portal.subscriptions.update', { catalog: String(row.catalogId) }), { muted: !row.muted }, { preserveScroll: true });
    const leave = () => router.delete(route('catalog.unfollow', { catalog: row.catalogSlug }), { preserveScroll: true });

    return (
        <article
            className={cn(
                'border-line bg-base-100 shadow-card flex flex-wrap items-center gap-3.5 rounded-2xl border p-3.5',
                !row.active && 'opacity-70',
            )}
            aria-label={row.catalogName}
        >
            <span aria-hidden="true" className="bg-surface-2 text-ink-3 flex size-16 shrink-0 items-center justify-center rounded-[10px]">
                <Icon icon={cilLibrary} size={23} />
            </span>

            <div className="flex min-w-0 flex-1 basis-56 flex-col gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="truncate text-[15px] font-bold">
                        {row.url !== null ? (
                            <a href={row.url} className="hover:underline">
                                {row.catalogName}
                            </a>
                        ) : (
                            row.catalogName
                        )}
                    </h2>
                    {row.active ? (
                        row.newCount > 0 ? (
                            <Badge tone="primary" count>
                                {row.newCount === 1 ? '1 nuevo' : `${row.newCount} nuevos`}
                            </Badge>
                        ) : null
                    ) : (
                        <Badge tone="neutral">No disponible ahora</Badge>
                    )}
                </div>
                <p className="text-ink-3 flex items-center gap-1.5 text-xs">
                    <Avatar name={row.businessName} size={18} tone="accent" />
                    <span>{row.businessName}</span>
                    {row.active ? (
                        <>
                            <span aria-hidden="true">·</span>
                            <span>{row.productsCount === 1 ? '1 producto' : `${row.productsCount} productos`}</span>
                        </>
                    ) : null}
                </p>
                {row.active ? null : (
                    <p className="text-ink-3 text-xs">El negocio no lo tiene publicado. Si vuelve a publicarlo, lo verás aquí otra vez.</p>
                )}
            </div>

            <div className="flex items-center gap-3 max-sm:w-full max-sm:justify-between">
                <label className="text-ink-2 flex cursor-pointer items-center gap-2.5 text-[13px] font-medium">
                    <input type="checkbox" className="toggle toggle-sm toggle-primary" checked={!row.muted} onChange={toggleMute} />
                    Avisarme de novedades
                </label>

                <button type="button" className="btn btn-ghost btn-sm" onClick={leave}>
                    Dejar de seguir
                </button>
            </div>
        </article>
    );
}
