import { Badge } from '@/components/ui/badge';
import { ProductPlaceholder } from '@/features/catalog/components/ProductPlaceholder';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { ProductRowActions } from '@/features/products/components/ProductRowActions';
import { cn } from '@/lib/utils';
import { type DataTableColumn } from '@/types/data-table';
import { type ProductListItem, type ProductStatus } from '@/types/product';

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

function Thumb({ product }: { product: ProductListItem }) {
    return product.thumbUrl ? (
        <img src={product.thumbUrl} alt="" loading="lazy" className="bg-base-300 size-11 shrink-0 rounded-[10px] object-cover" />
    ) : (
        <ProductPlaceholder name={product.name} className="size-11 shrink-0 rounded-[10px] text-base" />
    );
}

function StockLine({ product }: { product: ProductListItem }) {
    return (
        <span className="whitespace-nowrap">
            {product.stock === 0 ? <span className="text-error font-medium">Agotado</span> : `${product.stock} disp.`}
            {product.reservedUnits > 0 ? ` · ${product.reservedUnits === 1 ? '1 apartada' : `${product.reservedUnits} apartadas`}` : null}
            {product.shortfallUnits > 0 ? (
                <span className="text-error">
                    {' · '}
                    {product.shortfallUnits === 1 ? 'falta 1' : `faltan ${product.shortfallUnits}`}
                </span>
            ) : null}
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
            mobile: {
                media: (product) => (
                    <span className={cn('block', product.status === 'archived' && 'opacity-60')}>
                        <Thumb product={product} />
                    </span>
                ),
                title: (product) => product.name,
            },
            cell: (product) => (
                <div className={cn('flex items-center gap-3', product.status === 'archived' && 'opacity-60')}>
                    <Thumb product={product} />
                    <div className="flex min-w-0 flex-col">
                        <span className="font-semibold">{product.name}</span>
                        <span className="text-ink-3 text-xs">
                            {product.sku}
                            {product.plan
                                ? ` · ${Number(product.plan.downPayment.cents) === 0 ? 'Sin inicial · ' : `Inicial ${product.plan.downPayment.usd} + `}${product.plan.installments} ${product.plan.installments === 1 ? 'cuota' : 'cuotas'}`
                                : ''}
                        </span>
                    </div>
                </div>
            ),
        },
        {
            id: 'status',
            header: 'Estado',
            mobile: 'status',
            cell: (product) => <StatusBadges product={product} label={statusLabels[product.status]} />,
        },
        {
            id: 'price',
            header: 'Precio',
            sortKey: 'price',
            mobile: 'amount',
            cell: (product) => <MoneyLabel money={product.price} compact className="font-bold" />,
        },
        {
            id: 'stock',
            header: 'Stock',
            sortKey: 'stock',
            mobile: { meta: (product) => <StockLine product={product} /> },
            cell: (product) => <StockCell product={product} />,
        },
        {
            id: 'category',
            header: 'Categoría',
            mobile: 'hidden',
            cell: (product) => product.categoryName ?? <span className="text-ink-3 text-[13px]">Sin categoría</span>,
        },
        {
            id: 'catalogs',
            header: 'Catálogos',
            mobile: {
                meta: (product) =>
                    product.status === 'published' && product.catalogsCount === 0 ? (
                        <span className="text-warning font-semibold">En ningún catálogo</span>
                    ) : null,
            },
            cell: (product) => <CatalogsCell product={product} />,
        },
        {
            id: 'updated',
            header: 'Actualizado',
            sortKey: 'updated',
            mobile: 'hidden',
            cell: (product) => <LastEdit at={product.updatedAt} by={product.updatedBy} />,
        },
        { id: 'actions', header: 'Acciones', align: 'end', mobile: 'action', cell: (product) => <ProductRowActions product={product} /> },
    ];
}
