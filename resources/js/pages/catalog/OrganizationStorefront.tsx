import CatalogLayout from '@/layouts/catalog-layout';
import { type OrganizationStorefrontProps } from '@/types/catalog-view';

/**
 * La vitrina del negocio: `apartar.store/{slug}`, lo que va en su bio de
 * Instagram (`REQ-ORG-006` RB-5, RB-6, SP-4).
 *
 * Sus catálogos **listados** y nada más: ni los que comparte solo por enlace,
 * ni los que caducaron, ni un directorio de otros negocios, que no existe. La
 * marca y el sello los pone el mismo `CatalogLayout` que la vista del
 * catálogo, con la misma regla: en Free, logo y color guardados pero no
 * aplicados, y el sello «Hecho con Apartar».
 *
 * ## Vacía no es rota (SP-4)
 *
 * Si el negocio activó la vitrina y todavía no tiene nada publicado, la página
 * lo dice. Un `404` le haría creer que el enlace que acaba de compartir está
 * mal.
 *
 * Cada catálogo enlaza a su URL completa con un `<a>` y no con `<Link>`: es la
 * misma que el negocio difunde, y abrirla es entrar en otro catálogo con su
 * propio tenant y su propio Open Graph, no una navegación dentro de esta
 * página.
 */

export default function OrganizationStorefront({ business, showsPlatformBadge, catalogs }: OrganizationStorefrontProps) {
    return (
        <CatalogLayout title={business.name} business={business} showsPlatformBadge={showsPlatformBadge}>
            <h1 className="mb-6 text-2xl font-bold">Catálogos de {business.name}</h1>

            {catalogs.length === 0 ? (
                <div className="bg-base-100 rounded-box p-8 text-center">
                    <p className="font-medium">{business.name} todavía no tiene catálogos publicados.</p>
                    <p className="mt-2 text-sm opacity-70">Vuelve pronto: aquí aparecerán sus campañas en cuanto las publique.</p>
                </div>
            ) : (
                <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {catalogs.map((catalog) => (
                        <li key={catalog.slug}>
                            <a href={catalog.url} className="card bg-base-100 h-full shadow-sm transition-shadow hover:shadow-md">
                                <figure className="bg-base-200 aspect-video">
                                    {catalog.cover !== null ? (
                                        <img src={catalog.cover.card} alt={catalog.cover.alt} loading="lazy" className="h-full w-full object-cover" />
                                    ) : null}
                                </figure>
                                <div className="card-body gap-2 p-4">
                                    <h2 className="card-title text-base">{catalog.name}</h2>
                                    {catalog.description !== null ? <p className="line-clamp-3 text-sm opacity-80">{catalog.description}</p> : null}
                                </div>
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </CatalogLayout>
    );
}
