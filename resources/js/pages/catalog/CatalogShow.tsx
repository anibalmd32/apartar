import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { CatalogFilterPanel } from '@/features/catalog/components/CatalogFilterPanel';
import { CatalogNoResults } from '@/features/catalog/components/CatalogNoResults';
import { CatalogProductCard } from '@/features/catalog/components/CatalogProductCard';
import { SearchInput } from '@/features/catalog/components/SearchInput';
import { useCatalogFilters } from '@/features/catalog/hooks/useCatalogFilters';
import CatalogLayout from '@/layouts/catalog-layout';
import { type CatalogShowProps } from '@/types/catalog-view';

/**
 * El enlace que alguien recibió por WhatsApp (`REQ-CAT-021`), con su buscador,
 * sus filtros y su orden (`REQ-CAT-002`, `REQ-CAT-006`).
 *
 * Doce productos en el orden que eligió el negocio y «Cargar más» debajo,
 * sin números de página (RB-6). Cada card trae su precio, su inicial y su
 * cuota ya proyectados a bolívares con la tasa del sello (RB-7).
 *
 * Todo lo que se busca o se filtra queda **dentro de este catálogo** (RB-5):
 * no hay búsqueda entre negocios ni páginas de categoría, porque la taxonomía
 * es de cada negocio y «Motores» de uno no es el de otro.
 *
 * ## Tres formas de no ver productos, y no son la misma
 *
 *  - el catálogo no tiene nada visible (SP-4): el enlace sigue siendo válido
 *    y la página lo dice, invitando a escribirle al negocio;
 *  - lo buscado o filtrado no dio nada: se ofrece volver a todo el catálogo o
 *    a una de sus categorías (`CatalogNoResults`);
 *  - el enlace traía un filtro que ya no existe: se quita, se avisa, y se ve lo
 *    demás (`REQ-CAT-006` SP-1).
 *
 * ## Lo que esta página todavía no tiene, y no finge tener
 *
 * El botón «Seguir este catálogo» (RB-8) necesita cuentas de consumidor, que
 * son H6, y un botón deshabilitado «para que se vea» sería un botón que no
 * hace nada. Y ninguna búsqueda ni filtro emite telemetría todavía: es `T-084`.
 */

export default function CatalogShow({ catalog, products, filters, facets }: CatalogShowProps) {
    const filtering = useCatalogFilters(products, filters);
    const { pages } = filtering;
    const hasProducts = facets.categories.length > 0 || pages.items.length > 0;

    return (
        <CatalogLayout title={catalog.name} business={catalog.business} showsPlatformBadge={catalog.showsPlatformBadge}>
            <section className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center">
                {catalog.cover !== null ? (
                    <img src={catalog.cover.card} alt={catalog.cover.alt} className="rounded-box aspect-video w-full object-cover sm:w-80" />
                ) : null}

                <div className="flex flex-col gap-2">
                    <h1 className="text-2xl font-bold">{catalog.name}</h1>
                    {catalog.description !== null ? <p className="opacity-80">{catalog.description}</p> : null}
                </div>
            </section>

            {hasProducts ? (
                <section aria-label="Buscar y filtrar" className="mb-6 flex flex-col gap-4">
                    <SearchInput value={filtering.query} onChange={filtering.setQuery} />
                    <CatalogFilterPanel
                        facets={facets}
                        applied={filters}
                        searching={filters.q !== null}
                        onCategory={filtering.setCategory}
                        onToggleTag={filtering.toggleTag}
                        onSort={filtering.setSort}
                    />
                </section>
            ) : null}

            {filters.ignored ? (
                <Alert tone="info" className="mb-4">
                    Algún filtro del enlace ya no está en este catálogo y lo hemos quitado.
                </Alert>
            ) : null}

            {pages.items.length === 0 && filtering.isNarrowed ? (
                <CatalogNoResults
                    categories={facets.categories}
                    businessName={catalog.business.name}
                    onClear={filtering.clear}
                    onCategory={filtering.showCategory}
                />
            ) : pages.items.length === 0 ? (
                <div className="bg-base-100 rounded-box p-8 text-center">
                    <p className="font-medium">Este catálogo no tiene productos disponibles ahora mismo.</p>
                    <p className="mt-2 text-sm opacity-70">
                        Si buscabas algo en concreto, escríbele a {catalog.business.name}: puede que lo tenga en camino.
                    </p>
                </div>
            ) : (
                <>
                    <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {pages.items.map((product) => (
                            <li key={product.slug}>
                                <CatalogProductCard product={product} />
                            </li>
                        ))}
                    </ul>

                    {pages.hasMore ? (
                        <div className="mt-6 flex justify-center">
                            <Button variant="secondary" block className="sm:w-auto" loading={pages.loading} onClick={pages.loadMore}>
                                Cargar más
                            </Button>
                        </div>
                    ) : null}
                </>
            )}
        </CatalogLayout>
    );
}
