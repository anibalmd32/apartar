import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type DataTableColumn } from '@/types/data-table';
import { type ProductListItem, type ProductStatus } from '@/types/product';
import { Link, router } from '@inertiajs/react';

/**
 * Las columnas del inventario en la tabla server-driven (`REQ-BOF-020` RB-9,
 * `REQ-BOF-045`).
 *
 * Heredan lo que decía la fila del listado antes de `T-038`, y lo siguen
 * diciendo por lo mismo:
 *
 *  - **En cuántos catálogos está** (`REQ-CAT-007` RB-6): publicado y en cero
 *    catálogos no es un error, pero hay que poder verlo.
 *  - **Programado, y desde cuándo** (SP-5), resuelto por el servidor en la zona
 *    del negocio.
 *  - **Agotado sin dejar de estar publicado** (RB-4): el sello va junto al de
 *    estado, no en su lugar.
 *  - **Archivado no es borrado** (RB-10).
 *
 * Y dos nuevas: **la última modificación con su autor** (SP-7) y, bajo las
 * disponibles, **las apartadas y las que faltan** (`REQ-LAY-003` RB-6, SP-2,
 * desde `T-044`). Los botones son
 * la matriz de `ChangeProductStatusAction` y no una lista de verbos: cada
 * estado ofrece solo los pasos que la acción acepta desde él (`REQ-CAT-007`
 * RB-1, SP-4).
 *
 * Las columnas que ordenan declaran la clave de `InventorySort`: el servidor
 * ignora cualquier otra.
 */

const STATUS_TONES: Readonly<Record<ProductStatus, 'neutral' | 'success' | 'warning'>> = {
    draft: 'neutral',
    published: 'success',
    archived: 'warning',
};

function StatusBadges({ product, label }: { product: ProductListItem; label: string }) {
    return (
        <div className="flex flex-wrap gap-1">
            <Badge tone={STATUS_TONES[product.status]}>{label}</Badge>
            {product.scheduledFor === null ? null : (
                <Badge tone="info" outline>
                    Programado: {product.scheduledFor}
                </Badge>
            )}
            {product.stock === 0 ? <Badge tone="error">Agotado</Badge> : null}
            {product.status === 'published' && product.catalogsCount === 0 ? (
                <Badge tone="warning" outline>
                    En 0 catálogos
                </Badge>
            ) : (
                <Badge tone="neutral" outline>
                    {product.catalogsCount === 1 ? 'En 1 catálogo' : `En ${product.catalogsCount} catálogos`}
                </Badge>
            )}
        </div>
    );
}

function Actions({ product }: { product: ProductListItem }) {
    const step = (path: string) => router.post(`/backoffice/productos/${product.id}/${path}`, {}, { preserveScroll: true });

    return (
        <div className="flex flex-wrap justify-end gap-1">
            <Link className="btn btn-ghost btn-sm" href={`/backoffice/productos/${product.id}/editar`}>
                Editar
            </Link>
            {product.status === 'draft' ? (
                <Button size="sm" variant="ghost" onClick={() => step('publicar')}>
                    Publicar
                </Button>
            ) : null}
            {product.status === 'published' ? (
                <Button size="sm" variant="ghost" onClick={() => step('despublicar')}>
                    Despublicar
                </Button>
            ) : null}
            {product.status === 'archived' ? (
                <Button size="sm" variant="ghost" onClick={() => step('restaurar')}>
                    Restaurar
                </Button>
            ) : (
                <Button size="sm" variant="ghost" onClick={() => step('archivar')}>
                    Archivar
                </Button>
            )}
        </div>
    );
}

/**
 * Las disponibles, con las apartadas debajo y el faltante como alerta
 * (`REQ-LAY-003` RB-6, SP-2). Las tres llegan calculadas en la consulta del
 * listado: aquí no se pide nada por fila.
 */
export function StockCell({ product }: { product: ProductListItem }) {
    return (
        <div className="flex flex-col items-start gap-1">
            <span>{product.stock}</span>
            {product.reservedUnits > 0 ? <span className="text-xs opacity-70">{product.reservedUnits} apartadas</span> : null}
            {product.shortfallUnits > 0 ? (
                <Badge tone="warning">{product.shortfallUnits === 1 ? 'Falta 1 apartada' : `Faltan ${product.shortfallUnits} apartadas`}</Badge>
            ) : null}
        </div>
    );
}

/** La marca de SP-7: fecha y autor, o solo la fecha si el autor no se sabe. */
export function LastEdit({ at, by }: { at: string | null; by: string | null }) {
    if (at === null) {
        return null;
    }

    return (
        <span className="text-xs opacity-70">
            {at}
            {by !== null ? ` · ${by}` : null}
        </span>
    );
}

/**
 * @param statusLabels las etiquetas de los tres estados, resueltas por el servidor
 */
export function inventoryColumns(statusLabels: Readonly<Record<ProductStatus, string>>): DataTableColumn<ProductListItem>[] {
    return [
        {
            id: 'product',
            header: 'Producto',
            sortKey: 'name',
            primary: true,
            cell: (product) => (
                <div className={`flex flex-col ${product.status === 'archived' ? 'opacity-60' : ''}`}>
                    <span className="font-medium">{product.name}</span>
                    <span className="font-mono text-xs opacity-70">{product.sku}</span>
                </div>
            ),
        },
        {
            id: 'category',
            header: 'Categoría',
            cell: (product) => product.categoryName ?? <span className="text-xs opacity-70">Sin categoría</span>,
        },
        { id: 'price', header: 'Precio', sortKey: 'price', primary: true, cell: (product) => <MoneyLabel money={product.price} /> },
        { id: 'stock', header: 'Disponibles', sortKey: 'stock', cell: (product) => <StockCell product={product} /> },
        {
            id: 'status',
            header: 'Estado',
            primary: true,
            cell: (product) => <StatusBadges product={product} label={statusLabels[product.status]} />,
        },
        {
            id: 'updated',
            header: 'Modificado',
            sortKey: 'updated',
            cell: (product) => <LastEdit at={product.updatedAt} by={product.updatedBy} />,
        },
        { id: 'actions', header: 'Acciones', align: 'end', cell: (product) => <Actions product={product} /> },
    ];
}
