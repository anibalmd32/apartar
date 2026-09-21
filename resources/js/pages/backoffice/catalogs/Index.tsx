import { Alert } from '@/components/ui/alert';
import { CatalogVisibilityBadge } from '@/features/catalogs/components/CatalogVisibilityBadge';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type CatalogListItem } from '@/types/catalog';
import { Link, usePage } from '@inertiajs/react';

/**
 * Los catálogos del negocio (`REQ-BOF-024` RB-5, RB-6).
 *
 * Una lista, con el estado derivado de cada catálogo, su enlace completo y
 * cuántos productos tiene. **Publicar no se hace desde aquí**, al revés que en
 * el listado de productos: publicar un catálogo decide además si aparece en la
 * vitrina y cuándo caduca, y esas dos son decisiones con formulario. Un botón
 * suelto en la fila tendría que elegirlas por su cuenta.
 *
 * El estado no se guarda en ninguna columna y por eso esta pantalla siempre
 * dice la verdad: una campaña que caducó anoche se lee «Caducado» aunque no
 * haya corrido ningún job (`REQ-CAT-022` RB-6).
 */

interface CatalogsPageProps {
    catalogs: CatalogListItem[];
    [key: string]: unknown;
}

export default function CatalogsIndex({ catalogs }: CatalogsPageProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    return (
        <BackofficeLayout
            title="Catálogos"
            description="Tus campañas. Cada catálogo tiene su propio enlace: lo compartes por WhatsApp y tus clientes entran por ahí."
        >
            {errors.status ? <Alert tone="warning">{errors.status}</Alert> : null}

            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <header className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="card-title text-base">Tus catálogos</h2>

                        <Link className="btn btn-primary btn-sm" href="/backoffice/catalogos/crear">
                            Nuevo catálogo
                        </Link>
                    </header>

                    {catalogs.length === 0 ? (
                        <p className="text-sm opacity-70">
                            Todavía no tienes catálogos. Crea uno, añádele productos y publícalo: el enlace que te dé es lo que compartes con tus
                            clientes.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {catalogs.map((catalog) => (
                                <li key={catalog.id} className="border-base-300 flex flex-col gap-1 rounded-lg border p-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Link className="link link-hover font-medium" href={`/backoffice/catalogos/${catalog.id}/editar`}>
                                            {catalog.name}
                                        </Link>

                                        <CatalogVisibilityBadge visibility={catalog.visibility} />

                                        <span className="ml-auto text-sm opacity-70">
                                            {catalog.productsCount === 1 ? '1 producto' : `${catalog.productsCount} productos`}
                                        </span>
                                    </div>

                                    {/* RB-5 · la URL completa, y desde el
                                        borrador: el slug pudo desambiguarse
                                        con sufijo sin avisar (SP-11), así que
                                        el negocio tiene que verlo antes de
                                        difundirlo. */}
                                    <span className="font-mono text-xs break-all opacity-70">{catalog.shareUrl}</span>

                                    {catalog.expiresAt === null ? null : (
                                        <span className="text-xs opacity-70">
                                            {catalog.visibility === 'expired' ? 'Caducó el ' : 'Caduca el '}
                                            {new Date(catalog.expiresAt).toLocaleDateString('es-VE')}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </section>
        </BackofficeLayout>
    );
}
