import { menuItemClass } from '@/components/shell/user-menu';
import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { ProductPlaceholder } from '@/features/catalog/components/ProductPlaceholder';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { cn } from '@/lib/utils';
import { type DataTableColumn } from '@/types/data-table';
import { type ProductListItem, type ProductStatus } from '@/types/product';
import { cilArrowCircleTop, cilBan, cilOptions, cilPencil, cilReload, cilTrash } from '@coreui/icons';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { Link, router } from '@inertiajs/react';

const STATUS_TONES: Readonly<Record<ProductStatus, 'neutral' | 'success'>> = {
    draft: 'neutral',
    published: 'success',
    archived: 'neutral',
};

function StatusBadges({ product, label }: { product: ProductListItem; label: string }) {
    return (
        <div className="flex flex-wrap gap-1">
            {product.scheduledFor === null ? (
                <Badge tone={STATUS_TONES[product.status]}>{label}</Badge>
            ) : (
                <Badge tone="info">Programado {product.scheduledFor}</Badge>
            )}
        </div>
    );
}

function Actions({ product }: { product: ProductListItem }) {
    const step = (path: string) => router.post(`/backoffice/productos/${product.id}/${path}`, {}, { preserveScroll: true });
    const item = (icon: string[], label: string, path: string) => (
        <MenuItem>
            <button type="button" className={menuItemClass} onClick={() => step(path)}>
                <Icon icon={icon} />
                {label}
            </button>
        </MenuItem>
    );

    return (
        <Menu as="div" className="relative inline-block">
            <MenuButton className="btn btn-ghost btn-sm btn-square" aria-label={`Acciones de ${product.name}`}>
                <Icon icon={cilOptions} />
            </MenuButton>
            <MenuItems anchor="bottom end" className="bg-base-100 border-line shadow-pop z-50 mt-1 w-48 rounded-xl border p-1.5 focus:outline-none">
                <MenuItem>
                    <Link className={menuItemClass} href={`/backoffice/productos/${product.id}/editar`}>
                        <Icon icon={cilPencil} />
                        Editar
                    </Link>
                </MenuItem>
                {product.status === 'draft' ? item(cilArrowCircleTop, 'Publicar', 'publicar') : null}
                {product.status === 'published' ? item(cilBan, 'Despublicar', 'despublicar') : null}
                {product.status === 'archived' ? item(cilReload, 'Restaurar', 'restaurar') : item(cilTrash, 'Archivar', 'archivar')}
            </MenuItems>
        </Menu>
    );
}

export function StockCell({ product }: { product: ProductListItem }) {
    return (
        <div className="flex flex-col text-[13px]">
            <span className={cn('font-medium', product.stock === 0 && 'text-error')}>
                <span>{product.stock}</span> disp.{product.stock === 0 ? <span className="sr-only"> (agotado)</span> : null}
            </span>
            {product.reservedUnits > 0 ? (
                <span className="text-ink-3 text-xs">{product.reservedUnits === 1 ? '1 apartada' : `${product.reservedUnits} apartadas`}</span>
            ) : null}
            {product.shortfallUnits > 0 ? (
                <span className="text-error text-xs">
                    {product.shortfallUnits === 1 ? 'Falta 1 apartada' : `Faltan ${product.shortfallUnits} apartadas`}
                </span>
            ) : null}
        </div>
    );
}

export function LastEdit({ at, by }: { at: string | null; by: string | null }) {
    if (at === null) {
        return null;
    }

    return (
        <span className="text-ink-2 text-[13px]">
            {by !== null ? `${by} · ` : null}
            {at}
        </span>
    );
}

function CatalogsCell({ product }: { product: ProductListItem }) {
    const label = `en ${product.catalogsCount}`;

    return product.status === 'published' && product.catalogsCount === 0 ? (
        <span className="text-warning text-[13px] font-semibold" title="Publicado, pero en ningún catálogo">
            {label}
            <span className="sr-only"> catálogos: publicado pero no se ve en ninguno</span>
        </span>
    ) : (
        <span className="text-ink-2 text-[13px]">
            {label}
            <span className="sr-only"> {product.catalogsCount === 1 ? 'catálogo' : 'catálogos'}</span>
        </span>
    );
}

/**
 * @param statusLabels
 */
export function inventoryColumns(statusLabels: Readonly<Record<ProductStatus, string>>): DataTableColumn<ProductListItem>[] {
    return [
        {
            id: 'product',
            header: 'Producto',
            sortKey: 'name',
            primary: true,
            cell: (product) => (
                <div className={cn('flex items-center gap-3', product.status === 'archived' && 'opacity-60')}>
                    {product.thumbUrl ? (
                        <img src={product.thumbUrl} alt="" loading="lazy" className="bg-base-300 size-11 shrink-0 rounded-[10px] object-cover" />
                    ) : (
                        <ProductPlaceholder name={product.name} className="size-11 shrink-0 rounded-[10px] text-base" />
                    )}
                    <div className="flex min-w-0 flex-col">
                        <span className="font-semibold">{product.name}</span>
                        <span className="text-ink-3 text-xs">
                            {product.sku}
                            {product.plan
                                ? ` · Inicial ${product.plan.downPayment.usd} + ${product.plan.installments} ${product.plan.installments === 1 ? 'cuota' : 'cuotas'}`
                                : ''}
                        </span>
                    </div>
                </div>
            ),
        },
        {
            id: 'status',
            header: 'Estado',
            primary: true,
            cell: (product) => <StatusBadges product={product} label={statusLabels[product.status]} />,
        },
        {
            id: 'price',
            header: 'Precio',
            sortKey: 'price',
            primary: true,
            cell: (product) => <MoneyLabel money={product.price} compact className="font-bold" />,
        },
        { id: 'stock', header: 'Stock', sortKey: 'stock', cell: (product) => <StockCell product={product} /> },
        {
            id: 'category',
            header: 'Categoría',
            cell: (product) => product.categoryName ?? <span className="text-ink-3 text-[13px]">Sin categoría</span>,
        },
        { id: 'catalogs', header: 'Catálogos', cell: (product) => <CatalogsCell product={product} /> },
        {
            id: 'updated',
            header: 'Actualizado',
            sortKey: 'updated',
            cell: (product) => <LastEdit at={product.updatedAt} by={product.updatedBy} />,
        },
        { id: 'actions', header: 'Acciones', align: 'end', cell: (product) => <Actions product={product} /> },
    ];
}
