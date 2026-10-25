import { Icon } from '@/components/ui/icon';
import { ProductPlaceholder } from '@/features/catalog/components/ProductPlaceholder';
import { type StorefrontCatalog } from '@/types/catalog-view';
import { type CurrencyPageProps } from '@/types/exchange';
import { cilArrowRight, cilClock } from '@coreui/icons';
import { usePage } from '@inertiajs/react';

function closing(iso: string, timeZone: string): string {
    return new Intl.DateTimeFormat('es-VE', { day: '2-digit', month: '2-digit', timeZone }).format(new Date(iso));
}

export function StorefrontCatalogCard({ catalog }: { catalog: StorefrontCatalog }) {
    const { currency } = usePage<{ currency: CurrencyPageProps }>().props;

    return (
        <a
            href={catalog.url}
            className="group bg-base-100 border-line shadow-card hover:shadow-pop focus-visible:outline-primary flex h-full flex-col overflow-hidden rounded-2xl border transition-shadow focus-visible:outline-2 focus-visible:outline-offset-2"
        >
            <figure className="h-40 shrink-0 overflow-hidden">
                {catalog.thumbs !== undefined && catalog.thumbs.length > 0 ? (
                    <div className="grid h-full gap-0.5" style={{ gridTemplateColumns: `repeat(${catalog.thumbs.length}, minmax(0, 1fr))` }}>
                        {catalog.thumbs.map((thumb) => (
                            <img key={thumb} src={thumb} alt="" loading="lazy" className="h-full w-full object-cover" />
                        ))}
                    </div>
                ) : catalog.cover !== null ? (
                    <img src={catalog.cover.card} alt={catalog.cover.alt} loading="lazy" className="h-full w-full object-cover" />
                ) : (
                    <ProductPlaceholder name={catalog.name} />
                )}
            </figure>

            <div className="flex flex-1 flex-col items-start gap-1.5 p-[18px]">
                <h3 className="text-lg leading-[1.4] font-bold">{catalog.name}</h3>
                {catalog.description !== null ? <p className="text-ink-2 line-clamp-3 text-[13px]">{catalog.description}</p> : null}
                {catalog.productsCount !== undefined ? (
                    <p className="text-ink-3 text-xs font-semibold">
                        {catalog.productsCount} {catalog.productsCount === 1 ? 'producto' : 'productos'}
                    </p>
                ) : null}
                {catalog.expiresAt !== null ? (
                    <span className="bg-warning-soft text-warning inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold">
                        <Icon icon={cilClock} size={12} />
                        Hasta el {closing(catalog.expiresAt, currency.timezone)}
                    </span>
                ) : null}
                <span className="btn btn-secondary btn-block mt-auto group-hover:brightness-95" aria-hidden="true">
                    Ver catálogo
                    <Icon icon={cilArrowRight} />
                </span>
            </div>
        </a>
    );
}
