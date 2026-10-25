import { Badge } from '@/components/ui/badge';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type ProductCard } from '@/types/catalog-view';
import { Link } from '@inertiajs/react';

interface CatalogProductCardProps {
    product: ProductCard;
}

function frequencyLabel(days: number): string {
    return days === 1 ? 'cada día' : `cada ${days} días`;
}

export function CatalogProductCard({ product }: CatalogProductCardProps) {
    return (
        <Link
            href={route('catalog.product', { catalog: product.catalogSlug, product: product.slug })}
            className="rounded-box focus-visible:outline-primary block h-full focus-visible:outline-2"
        >
            <article className="card bg-base-100 h-full shadow-sm transition-shadow hover:shadow-md">
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
                        {product.installmentsCount === 1 ? 'cuota' : 'cuotas'} de{' '}
                        <MoneyLabel money={product.installmentAmount} className="font-medium" /> {frequencyLabel(product.frequencyDays)}
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
        </Link>
    );
}
