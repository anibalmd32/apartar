import { Badge } from '@/components/ui/badge';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type ProductCard } from '@/types/catalog-view';

/**
 * Un producto en la parrilla del catálogo (`REQ-CAT-021` RB-7).
 *
 * Precio, cuota inicial e importe de cuota, los tres en bimoneda y los tres
 * **como llegaron**: `MoneyLabel` elige cuál de las dos monedas va delante y
 * nada más. Sin tasa, las tres cifras salen solo en dólares, sin `Bs. 0,00`
 * ni guiones (`REQ-FX-005` RB-4), porque es `MoneyLabel` quien lo decide y no
 * esta card.
 *
 * ## Todavía no es un enlace
 *
 * La ficha del producto es `T-035`. Una card que lleva a un `404` es peor que
 * una que todavía no lleva a ninguna parte, así que el enlace entra con la
 * página a la que apunta.
 *
 * La foto va con `loading="lazy"` (`REQ-CAT-005` RB-3): es una parrilla que se
 * abre desde un móvil con datos contados, y solo se descargan las que se ven.
 */

interface CatalogProductCardProps {
    product: ProductCard;
}

function frequencyLabel(days: number): string {
    return days === 1 ? 'cada día' : `cada ${days} días`;
}

export function CatalogProductCard({ product }: CatalogProductCardProps) {
    return (
        <article className="card bg-base-100 shadow-sm">
            <figure className="bg-base-200 aspect-square">
                {product.image !== null ? (
                    <img src={product.image.card} alt={product.image.alt} loading="lazy" className="h-full w-full object-cover" />
                ) : null}
            </figure>

            <div className="card-body gap-2 p-4">
                <div className="flex items-start justify-between gap-2">
                    <h2 className="card-title text-base leading-snug">{product.name}</h2>
                    {!product.inStock ? <Badge tone="warning">Agotado</Badge> : null}
                </div>

                {product.categoryName !== '' ? <p className="text-xs opacity-60">{product.categoryName}</p> : null}

                <p>
                    <MoneyLabel money={product.price} className="text-lg font-semibold" />
                    {product.compareAtPrice !== null ? (
                        <span className="ml-2 text-sm line-through opacity-60">
                            <MoneyLabel money={product.compareAtPrice} />
                        </span>
                    ) : null}
                </p>

                <p className="text-sm">
                    Inicial <MoneyLabel money={product.downPayment} className="font-medium" /> y {product.installmentsCount}{' '}
                    {product.installmentsCount === 1 ? 'cuota' : 'cuotas'} de <MoneyLabel money={product.installmentAmount} className="font-medium" />{' '}
                    {frequencyLabel(product.frequencyDays)}
                    {product.lastInstallmentAmount !== null ? (
                        <>
                            {' '}
                            (la última, <MoneyLabel money={product.lastInstallmentAmount} />)
                        </>
                    ) : null}
                    .
                </p>
            </div>
        </article>
    );
}
