import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { IconChip } from '@/components/ui/icon-chip';
import { FollowCatalogButton } from '@/features/account/components/FollowCatalogButton';
import { CatalogFilterPanel } from '@/features/catalog/components/CatalogFilterPanel';
import { CatalogHero } from '@/features/catalog/components/CatalogHero';
import { CatalogNoResults } from '@/features/catalog/components/CatalogNoResults';
import { ProductGridCard } from '@/features/catalog/components/ProductGridCard';
import { SearchInput } from '@/features/catalog/components/SearchInput';
import { useCatalogFilters } from '@/features/catalog/hooks/useCatalogFilters';
import { ChatWidget } from '@/features/chat/components/ChatWidget';
import { useCatalogTelemetry } from '@/features/telemetry/hooks/useCatalogTelemetry';
import CatalogLayout from '@/layouts/catalog-layout';
import { type CatalogShowProps } from '@/types/catalog-view';
import { cilBasket } from '@coreui/icons';

export default function CatalogShow({ catalog, products, filters, facets, follow, chat, stats }: CatalogShowProps) {
    const filtering = useCatalogFilters(products, filters);
    const { pages } = filtering;
    useCatalogTelemetry(catalog.slug, filters, products.data.length);
    const hasProducts = facets.categories.length > 0 || pages.items.length > 0;

    return (
        <CatalogLayout title={catalog.name} business={catalog.business} showsPlatformBadge={catalog.showsPlatformBadge}>
            <CatalogHero
                stats={stats}
                catalog={catalog}
                action={
                    <div className="[&_.btn]:h-10 [&_.btn]:rounded-full [&_.btn]:px-4 [&_.btn]:text-sm">
                        <FollowCatalogButton follow={follow} />
                    </div>
                }
            />

            {hasProducts ? (
                <section aria-label="Buscar y filtrar">
                    <CatalogFilterPanel
                        facets={facets}
                        applied={filters}
                        searching={filters.q !== null}
                        search={<SearchInput value={filtering.query} onChange={filtering.setQuery} />}
                        onCategory={filtering.setCategory}
                        onToggleTag={filtering.toggleTag}
                        onSort={filtering.setSort}
                    />
                </section>
            ) : null}

            {filters.ignored ? <Alert tone="info">Algún filtro del enlace ya no está en este catálogo y lo hemos quitado.</Alert> : null}

            {pages.items.length === 0 && filtering.isNarrowed ? (
                <CatalogNoResults
                    categories={facets.categories}
                    businessName={catalog.business.name}
                    onClear={filtering.clear}
                    onCategory={filtering.showCategory}
                />
            ) : pages.items.length === 0 ? (
                <div className="bg-base-100 border-line shadow-card flex flex-col items-center gap-3 rounded-2xl border px-6 py-10 text-center">
                    <IconChip icon={cilBasket} tone="neutral" size={48} />
                    <p className="text-h2">Este catálogo no tiene productos disponibles ahora mismo.</p>
                    <p className="text-ink-3 max-w-md text-sm">
                        Si buscabas algo en concreto, escríbele a {catalog.business.name}: puede que lo tenga en camino.
                    </p>
                </div>
            ) : (
                <>
                    <ul className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4 lg:gap-5">
                        {pages.items.map((product) => (
                            <li key={product.slug}>
                                <ProductGridCard product={product} />
                            </li>
                        ))}
                    </ul>

                    {pages.hasMore ? (
                        <div className="flex justify-center">
                            <Button variant="secondary" size="lg" loading={pages.loading} onClick={pages.loadMore}>
                                Cargar más productos
                            </Button>
                        </div>
                    ) : null}
                </>
            )}

            <div className="[&>.btn]:bg-neutral [&>.btn]:text-neutral-content [&>.btn]:shadow-pop [&>form]:shadow-pop [&>p]:shadow-pop [&>form]:border-line [&>p]:border-line fixed right-4 bottom-4 z-30 flex max-w-[min(24rem,calc(100vw-2rem))] flex-col items-end lg:right-10 lg:bottom-8 [&>.btn]:h-[52px] [&>.btn]:rounded-full [&>.btn]:border-0 [&>.btn]:px-[18px] [&>.btn]:text-sm [&>.btn]:font-bold [&>form]:border [&>p]:border">
                <ChatWidget chat={chat} />
            </div>
        </CatalogLayout>
    );
}
