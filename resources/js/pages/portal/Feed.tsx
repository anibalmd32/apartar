import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { FeedList } from '@/features/account/components/FeedList';
import { useFeed } from '@/features/account/hooks/useFeed';
import { track } from '@/features/telemetry/hooks/useTelemetry';
import PortalLayout from '@/layouts/portal-layout';
import { type FeedPageProps } from '@/types/account';
import { cilSearch } from '@coreui/icons';
import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

const EMPTY = 'border-line bg-base-100 rounded-2xl border p-6 text-sm';
export default function Feed({ items, subscriptions, filters }: FeedPageProps) {
    const feed = useFeed(items, filters);
    const { auth } = usePage<{ auth?: { portal?: { fullName: string } | null } }>().props;
    const active = subscriptions.filter((row) => row.active);

    const firstView = useRef({ subscriptions_count: subscriptions.length, items: items.data.length });

    useEffect(() => {
        track('feed_viewed', { payload: firstView.current });
    }, []);

    return (
        <PortalLayout title="Novedades" businessName="Novedades" customerName={auth?.portal?.fullName ?? ''}>
            <header className="flex flex-col gap-1">
                <h1 className="text-[24px] leading-tight font-extrabold tracking-[-0.6px] lg:text-[30px]">Novedades</h1>
                <p className="text-ink-2 text-[15px]">Lo nuevo de los catálogos que sigues.</p>
            </header>

            {subscriptions.length === 0 ? (
                <div className={EMPTY}>
                    <p className="text-base font-bold">Todavía no sigues ningún catálogo.</p>
                    <p className="text-ink-2 mt-2">
                        Los catálogos se siguen desde el enlace que te comparte cada negocio: ábrelo y pulsa «Seguir este catálogo».
                    </p>
                </div>
            ) : active.length === 0 ? (
                <div className={EMPTY}>
                    <p className="text-base font-bold">Los catálogos que sigues no están disponibles ahora mismo.</p>
                    <ul className="text-ink-2 mt-2 list-inside list-disc">
                        {subscriptions.map((row) => (
                            <li key={row.catalogId}>
                                {row.catalogName} · {row.businessName}
                            </li>
                        ))}
                    </ul>
                    <p className="text-ink-2 mt-2">Si el negocio los vuelve a publicar, sus novedades aparecerán aquí.</p>
                </div>
            ) : (
                <>
                    <form
                        className="flex flex-col gap-2 sm:flex-row sm:items-center"
                        role="search"
                        onSubmit={(event) => {
                            event.preventDefault();
                            feed.search();
                        }}
                    >
                        <label className="input w-full sm:w-80">
                            <Icon icon={cilSearch} className="text-ink-3" />
                            <input
                                type="search"
                                className="grow"
                                aria-label="Buscar en las novedades"
                                placeholder="Buscar en novedades"
                                value={feed.query}
                                onChange={(event) => feed.setQuery(event.target.value)}
                            />
                        </label>
                        <select
                            className="select w-full sm:w-auto sm:min-w-52"
                            aria-label="Catálogo"
                            value={filters.catalog ?? ''}
                            onChange={(event) => feed.setCatalog(event.target.value === '' ? null : event.target.value)}
                        >
                            <option value="">Todos los catálogos</option>
                            {active.map((row) => (
                                <option key={row.catalogId} value={row.catalogSlug}>
                                    {row.catalogName} · {row.businessName}
                                </option>
                            ))}
                        </select>
                    </form>

                    {feed.pages.items.length === 0 ? (
                        <div className={EMPTY}>
                            <p>{feed.isFiltered ? 'Nada coincide con esos filtros.' : 'No hay novedades todavía.'}</p>
                            {feed.isFiltered ? (
                                <button type="button" className="text-primary-ink mt-2 font-semibold hover:underline" onClick={feed.clear}>
                                    Quitar los filtros
                                </button>
                            ) : null}
                        </div>
                    ) : (
                        <>
                            <FeedList items={feed.pages.items} grouped />
                            {feed.pages.hasMore ? (
                                <Button variant="secondary" loading={feed.pages.loading} onClick={feed.pages.loadMore} className="self-center">
                                    Cargar más
                                </Button>
                            ) : null}
                        </>
                    )}
                </>
            )}
        </PortalLayout>
    );
}
