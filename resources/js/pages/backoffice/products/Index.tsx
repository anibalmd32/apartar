import { Alert } from '@/components/ui/alert';
import { DataTable } from '@/features/data-table/components/DataTable';
import { useServerTable } from '@/features/data-table/hooks/useServerTable';
import { ExportButton } from '@/features/exports/components/ExportButton';
import { LimitBanner } from '@/features/plan/components/LimitBanner';
import { INVENTORY_FILTER_LABELS, InventoryFilters } from '@/features/products/components/InventoryFilters';
import { inventoryColumns } from '@/features/products/components/inventoryColumns';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type Paginated } from '@/types/data-table';
import { type ProductFilters, type ProductListItem, type ProductStatus, type ProductStatusDefinition, type TaxonomyOption } from '@/types/product';
import { Link, usePage } from '@inertiajs/react';
import { useMemo } from 'react';

/**
 * El inventario del negocio (`REQ-BOF-020` RB-9), como tabla server-driven
 * (`REQ-BOF-045`).
 *
 * Pagina, ordena y filtra **en el servidor**: antes de `T-038` esta pantalla se
 * traía el inventario entero en cada visita, y un negocio del plan Business
 * puede tener dos mil productos. El estado vive en la query string, así que la
 * vista se recarga, se comparte con el equipo y vuelve con el botón «atrás».
 *
 * ## Y es donde aterriza el rechazo de publicar
 *
 * `REQ-BOF-020` SP-3 pide «la lista de lo que falta», y la fila desde la que se
 * pulsó «Publicar» no tiene sitio para cuatro mensajes. Así que el resumen
 * llega bajo `status` y se pinta arriba; el detalle por campo lo lee el
 * formulario cuando se abre el producto para arreglarlo.
 */

interface ProductsPageProps extends Paginated<ProductListItem> {
    categories: TaxonomyOption[];
    tags: TaxonomyOption[];
    statuses: ProductStatusDefinition[];
    [key: string]: unknown;
}

/** La frase del estado vacío, con el filtro aplicado dicho en palabras (`REQ-BOF-045` RB-7). */
function emptyMessage(filters: ProductFilters, narrowed: boolean): string {
    if (!narrowed) {
        return 'Todavía no tienes productos. Crea el primero desde el botón de arriba.';
    }

    return filters.q !== null ? `Ningún producto coincide con «${filters.q}» y los demás filtros.` : 'Ningún producto cumple estos filtros.';
}

export default function ProductsIndex({ rows, meta, categories, tags, statuses }: ProductsPageProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    const statusLabels = useMemo(
        () => Object.fromEntries(statuses.map((status) => [status.value, status.label])) as Record<ProductStatus, string>,
        [statuses],
    );
    const columns = useMemo(() => inventoryColumns(statusLabels), [statusLabels]);

    const table = useServerTable({ rows, meta, columns });
    const filters = meta.filters as unknown as ProductFilters;
    const narrowed = Object.values(meta.filters).some((value) => value !== null);

    return (
        <BackofficeLayout
            title="Productos"
            description="Lo que vendes. Desde aquí lo creas, lo editas y lo archivas cuando deja de estar a la venta."
        >
            {errors.status ? <Alert tone="warning">{errors.status}</Alert> : null}

            {/* `REQ-SUB-003` RB-7 · a partir del 80 %, antes de chocar. */}
            <LimitBanner metric="products" />
            <LimitBanner metric="storage_mb" />

            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <header className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="card-title text-base">Tu inventario</h2>

                        <div className="flex flex-wrap items-center gap-2">
                            <ExportButton type="inventory" filters={meta.filters} />
                            <Link className="btn btn-primary btn-sm" href="/backoffice/productos/crear">
                                Nuevo producto
                            </Link>
                        </div>
                    </header>

                    <DataTable
                        label="Inventario"
                        table={table}
                        filterLabels={INVENTORY_FILTER_LABELS}
                        empty={emptyMessage(filters, narrowed)}
                        toolbar={
                            <InventoryFilters filters={filters} statuses={statuses} categories={categories} tags={tags} onChange={table.setFilter} />
                        }
                    />

                    <p className="text-xs opacity-70">
                        Archivar no borra: el producto conserva su historial y su sitio en los catálogos, y deja de mostrarse y de poder apartarse.
                        Restaurarlo lo devuelve como borrador, para que lo revises antes de que vuelva a verse.
                    </p>
                </div>
            </section>
        </BackofficeLayout>
    );
}
