import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type ProductListItem, type ProductStatus } from '@/types/product';
import { Link, router } from '@inertiajs/react';

/**
 * Un producto en el listado del backoffice (`REQ-BOF-020` RB-9).
 *
 * ## Cuatro cosas que la fila tiene que decir y no son el precio
 *
 *  - **En cuántos catálogos está** (`REQ-CAT-007` RB-6). Un producto puede
 *    estar publicado y no verlo nadie porque no pertenece a ningún catálogo, y
 *    eso **no es un error**: es una situación normal que el backoffice tiene
 *    que poder explicar, o el negocio se pasa la tarde buscando por qué su
 *    producto «no aparece».
 *  - **Programado, y desde cuándo** (`REQ-CAT-007` SP-5). Publicado con fecha
 *    futura es un producto que no se ve **y no ha fallado nada**. Sin el
 *    sello, quien acaba de programarlo lo busca en su catálogo, no lo
 *    encuentra y lo vuelve a publicar; sin la fecha, se queda sin saber
 *    cuándo dejar de mirar. Las dos cosas llegan resueltas del servidor, en
 *    la zona horaria del negocio: aquí no se comparan ni se formatean relojes.
 *  - **Agotado, sin dejar de estar publicado** (`REQ-CAT-007` RB-4). Cero
 *    existencias no cambia el estado: el producto sigue en el catálogo con su
 *    sello y lo que se deshabilita es apartarlo. Por eso el sello de agotado
 *    va **junto** al de estado y no en su lugar.
 *  - **Archivado no es borrado** (RB-10). La fila sigue aquí, marcada, porque
 *    esconderla convertiría el archivado en un borrado a efectos prácticos.
 *
 * El color nunca es lo único que distingue un estado: cada distintivo lleva su
 * texto.
 *
 * ## Los botones son la matriz, no una lista de verbos
 *
 * Cada estado ofrece **solo los pasos que la acción acepta desde él**
 * (`REQ-CAT-007` RB-1, SP-4), y por eso un archivado ofrece «Restaurar» y no
 * «Publicar»: restaurar devuelve a borrador para que alguien revise el
 * producto antes de que vuelva a los catálogos donde seguía. Esconder el botón
 * no es el control —quien manda es la matriz del servidor, que niega el paso
 * aunque la petición se componga a mano—, es no ofrecer lo que va a dar `422`.
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
    const step = (path: string) => router.post(`/backoffice/productos/${product.id}/${path}`, {}, { preserveScroll: true });

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
            </td>

            <td className="text-right">
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
            </td>
        </tr>
    );
}
