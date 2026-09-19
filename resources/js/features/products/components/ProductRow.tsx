import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type ProductListItem, type ProductStatus } from '@/types/product';
import { Link, router } from '@inertiajs/react';

/**
 * Un producto en el listado del backoffice (`REQ-BOF-020` RB-9).
 *
 * ## Tres cosas que la fila tiene que decir y no son el precio
 *
 *  - **En cuántos catálogos está** (`REQ-CAT-007` RB-6). Un producto puede
 *    estar publicado y no verlo nadie porque no pertenece a ningún catálogo, y
 *    eso **no es un error**: es una situación normal que el backoffice tiene
 *    que poder explicar, o el negocio se pasa la tarde buscando por qué su
 *    producto «no aparece».
 *  - **Agotado, sin dejar de estar publicado** (`REQ-CAT-007` RB-4). Cero
 *    existencias no cambia el estado: el producto sigue en el catálogo con su
 *    sello y lo que se deshabilita es apartarlo. Por eso el sello de agotado
 *    va **junto** al de estado y no en su lugar.
 *  - **Archivado no es borrado** (RB-10). La fila sigue aquí, marcada, porque
 *    esconderla convertiría el archivado en un borrado a efectos prácticos.
 *
 * El color nunca es lo único que distingue un estado: cada distintivo lleva su
 * texto.
 */

interface ProductRowProps {
    product: ProductListItem;
    /** Las etiquetas de los tres estados, resueltas por el servidor. */
    statusLabels: Readonly<Record<ProductStatus, string>>;
}

const STATUS_TONES: Readonly<Record<ProductStatus, 'neutral' | 'success' | 'warning'>> = {
    draft: 'neutral',
    published: 'success',
    archived: 'warning',
};

export function ProductRow({ product, statusLabels }: ProductRowProps) {
    const archive = () => router.post(`/backoffice/productos/${product.id}/archivar`, {}, { preserveScroll: true });

    return (
        <tr className={product.status === 'archived' ? 'opacity-60' : undefined}>
            <td>
                <div className="flex flex-col">
                    <span className="font-medium">{product.name}</span>
                    <span className="font-mono text-xs opacity-70">{product.sku}</span>
                </div>
            </td>

            <td>{product.categoryName ?? <span className="text-xs opacity-70">Sin categoría</span>}</td>

            <td>
                <MoneyLabel money={product.price} />
            </td>

            <td>
                <div className="flex flex-wrap gap-1">
                    <Badge tone={STATUS_TONES[product.status]}>{statusLabels[product.status]}</Badge>

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
            </td>

            <td className="text-right">
                <div className="flex flex-wrap justify-end gap-1">
                    <Link className="btn btn-ghost btn-sm" href={`/backoffice/productos/${product.id}/editar`}>
                        Editar
                    </Link>

                    {product.status === 'archived' ? null : (
                        <Button size="sm" variant="ghost" onClick={archive}>
                            Archivar
                        </Button>
                    )}
                </div>
            </td>
        </tr>
    );
}
