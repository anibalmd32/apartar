import { Button } from '@/components/ui/button';
import { CatalogProductCard } from '@/features/catalog/components/CatalogProductCard';
import { useCursorPages } from '@/features/catalog/hooks/useCursorPages';
import CatalogLayout from '@/layouts/catalog-layout';
import { type CatalogShowProps } from '@/types/catalog-view';

/**
 * El enlace que alguien recibió por WhatsApp (`REQ-CAT-021`).
 *
 * Doce productos en el orden que eligió el negocio y «Cargar más» debajo,
 * sin números de página (RB-6). Cada card trae su precio, su inicial y su
 * cuota ya proyectados a bolívares con la tasa del sello (RB-7).
 *
 * ## Lo que esta página todavía no tiene, y no finge tener
 *
 * El buscador, los filtros y el orden son de `T-034`: la consulta de facetas ya
 * existe, pero un panel que no filtra enseñaría una función que no está. El
 * botón «Seguir este catálogo» (RB-8) necesita cuentas de consumidor, que son
 * H6, y un botón deshabilitado «para que se vea» sería lo mismo con otra
 * forma.
 *
 * ## El catálogo vacío no es un error
 *
 * SP-4: si el negocio despublicó todos sus productos, el enlace sigue siendo
 * válido y el negocio sigue ahí. La página lo dice y lo invita a escribirle, en
 * lugar de un `404` que haría pensar que el enlace está roto.
 */

export default function CatalogShow({ catalog, products }: CatalogShowProps) {
    const pages = useCursorPages(products, 'products');

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

            {pages.items.length === 0 ? (
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
