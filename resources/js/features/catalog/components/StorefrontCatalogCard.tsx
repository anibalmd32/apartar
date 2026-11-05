import { Icon } from '@/components/ui/icon';
import { ProductPlaceholder } from '@/features/catalog/components/ProductPlaceholder';
import { formatDate } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { type StorefrontCatalog } from '@/types/catalog-view';
import { type CurrencyPageProps } from '@/types/exchange';
import { cilArrowRight, cilClock } from '@coreui/icons';
import { usePage } from '@inertiajs/react';

function closing(iso: string, timeZone: string): string {
    return formatDate(iso, 'dayMonth', timeZone);
}

function ctaLabel(catalog: StorefrontCatalog, featured: boolean): string {
    if (!featured || catalog.productsCount === undefined || catalog.productsCount === 0) {
        return 'Ver catálogo';
    }

    return catalog.productsCount === 1 ? 'Ver el producto' : `Ver los ${catalog.productsCount} productos`;
}

export function StorefrontCatalogCard({ catalog, featured = false }: { catalog: StorefrontCatalog; featured?: boolean }) {
    const { currency } = usePage<{ currency: CurrencyPageProps }>().props;

    return (
        <a
            href={catalog.url}
            data-featured={featured || undefined}
            className={cn(
                'group bg-base-100 border-line shadow-card hover:shadow-pop focus-visible:outline-primary flex h-full flex-col overflow-hidden rounded-2xl border transition-shadow focus-visible:outline-2 focus-visible:outline-offset-2',
                featured && 'md:flex-row',
            )}
        >
            <figure className={cn('shrink-0 overflow-hidden', featured ? 'h-56 md:h-auto md:min-h-72 md:w-1/2' : 'h-40')}>
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

            <div className={cn('flex flex-1 flex-col items-start gap-1.5 p-[18px]', featured && 'md:justify-center md:gap-2.5 md:p-8')}>
                <h3 className={cn('leading-[1.4] font-bold', featured ? 'text-xl md:text-2xl' : 'text-lg')}>{catalog.name}</h3>
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
                <span
                    className={cn('btn btn-block mt-auto group-hover:brightness-95', featured ? 'btn-primary md:mt-4 md:w-auto' : 'btn-secondary')}
                    aria-hidden="true"
                >
                    {ctaLabel(catalog, featured)}
                    <Icon icon={cilArrowRight} />
                </span>
            </div>
        </a>
    );
}
