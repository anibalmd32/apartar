import { Alert } from '@/components/ui/alert';
import { EmptyState } from '@/components/ui/empty-state';
import { Icon } from '@/components/ui/icon';
import { DataTable } from '@/features/data-table/components/DataTable';
import { useServerTable } from '@/features/data-table/hooks/useServerTable';
import { ExportButton } from '@/features/exports/components/ExportButton';
import { LimitBanner } from '@/features/plan/components/LimitBanner';
import { BulkPublishButton } from '@/features/products/components/BulkPublishButton';
import { INVENTORY_FILTER_LABELS, InventoryFilters } from '@/features/products/components/InventoryFilters';
import { inventoryColumns } from '@/features/products/components/inventoryColumns';
import { useDraftSelection } from '@/features/products/hooks/useDraftSelection';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type Paginated } from '@/types/data-table';
import { type ProductFilters, type ProductListItem, type ProductStatus, type ProductStatusDefinition, type TaxonomyOption } from '@/types/product';
import { cilBasket, cilPlus } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';
import { useMemo, type ReactNode } from 'react';

interface ProductsPageProps extends Paginated<ProductListItem> {
    categories: TaxonomyOption[];
    tags: TaxonomyOption[];
    statuses: ProductStatusDefinition[];
    statusCounts?: Record<string, number>;
    [key: string]: unknown;
}

function emptyMessage(filters: ProductFilters, narrowed: boolean): ReactNode {
    if (!narrowed) {
        return (
            <EmptyState
                icon={cilBasket}
                title="Aún no tienes productos"
                action={
                    <Link className="btn btn-primary" href="/backoffice/productos/crear">
                        <Icon icon={cilPlus} />
                        Crear mi primer producto
                    </Link>
                }
            >
                Pon el precio, la inicial y en cuántas cuotas se paga. Con un producto ya puedes armar tu catálogo.
            </EmptyState>
        );
    }

    return filters.q !== null ? `Ningún producto coincide con «${filters.q}» y los demás filtros.` : 'Ningún producto cumple estos filtros.';
}

export default function ProductsIndex({ rows, meta, categories, tags, statuses, statusCounts }: ProductsPageProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    const statusLabels = useMemo(
        () => Object.fromEntries(statuses.map((status) => [status.value, status.label])) as Record<ProductStatus, string>,
        [statuses],
    );
    const columns = useMemo(() => inventoryColumns(statusLabels), [statusLabels]);

    const table = useServerTable({ rows, meta, columns });
    const selection = useDraftSelection(rows);
    const filters = meta.filters as unknown as ProductFilters;
    const narrowed = Object.values(meta.filters).some((value) => value !== null);

    return (
        <BackofficeLayout
            title="Productos"
            description="Lo que vendes. Desde aquí lo creas, lo editas y lo archivas cuando deja de estar a la venta."
            actions={
                <>
                    <ExportButton type="inventory" filters={meta.filters} />
                    <Link className="btn btn-primary" href="/backoffice/productos/crear">
                        <Icon icon={cilPlus} />
                        Nuevo producto
                    </Link>
                </>
            }
        >
            {errors.status ? <Alert tone="warning">{errors.status}</Alert> : null}

            <LimitBanner metric="products" />
            <LimitBanner metric="storage_mb" />

            <DataTable
                label="Inventario"
                table={table}
                filterLabels={INVENTORY_FILTER_LABELS}
                empty={emptyMessage(filters, narrowed)}
                selection={selection}
                selectionActions={<BulkPublishButton ids={selection.ids} onDone={selection.clear} />}
                toolbar={
                    <InventoryFilters
                        filters={filters}
                        statuses={statuses}
                        categories={categories}
                        tags={tags}
                        onChange={table.setFilter}
                        sort={meta.sort}
                        onSort={table.setSort}
                        counts={statusCounts}
                        onChangeMany={table.setFilters}
                    />
                }
            />

            <p className="text-ink-3 text-xs">
                Archivar no borra: el producto conserva su historial y su sitio en los catálogos, y deja de mostrarse y de poder apartarse.
                Restaurarlo lo devuelve como borrador, para que lo revises antes de que vuelva a verse.
            </p>
        </BackofficeLayout>
    );
}
