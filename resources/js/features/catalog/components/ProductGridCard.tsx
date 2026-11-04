import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { PrimaryAmount } from '@/features/catalog/components/PrimaryAmount';
import { ProductPlaceholder } from '@/features/catalog/components/ProductPlaceholder';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type ProductCard } from '@/types/catalog-view';
import { cilCalendar } from '@coreui/icons';
import { Link } from '@inertiajs/react';

function frequencyLabel(days: number): string {
    return days === 1 ? 'cada día' : `cada ${days} días`;
}

export function ProductGridCard({ product }: { product: ProductCard }) {
    const withoutDownPayment = Number(product.downPayment.cents) === 0;

    return (
        <Link
            href={route('catalog.product', { catalog: product.catalogSlug, product: product.slug })}
            className="group focus-visible:outline-primary block h-full rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2"
        >
            <article className="bg-base-100 border-line group-hover:border-line-strong flex h-full flex-col overflow-hidden rounded-xl border transition-colors">
                <figure className="relative aspect-[305/290] overflow-hidden">
                    {product.image !== null ? (
                        <img src={product.image.card} alt={product.image.alt} loading="lazy" className="h-full w-full object-cover" />
                    ) : (
                        <ProductPlaceholder name={product.name} />
                    )}
                    {!product.inStock ? (
                        <Badge tone="error" className="absolute top-2.5 left-2.5">
                            Agotado
                        </Badge>
                    ) : product.isNew ? (
                        <Badge tone="primary" className="absolute top-2.5 left-2.5">
                            Nuevo
                        </Badge>
                    ) : null}
                </figure>

                <div className="flex flex-1 flex-col gap-1 px-3 pt-3 pb-3.5 lg:px-3.5">
                    <h2 className="line-clamp-2 text-[0.9375rem] leading-snug font-semibold">{product.name}</h2>

                    <div className="flex items-start gap-1.5">
                        <MoneyLabel money={product.price} stacked className="text-[17px] leading-6 font-extrabold" />
                        {product.compareAtPrice !== null ? (
                            <s className="text-ink-3 mt-1 text-xs">
                                <span className="sr-only">Antes </span>
                                <PrimaryAmount money={product.compareAtPrice} />
                            </s>
                        ) : null}
                    </div>

                    <p className="text-primary-ink mt-auto flex items-start gap-1.5 pt-1 text-[0.8125rem] leading-snug font-semibold">
                        <Icon icon={cilCalendar} size={14} className="mt-0.5 shrink-0" />
                        <span aria-hidden="true">
                            {withoutDownPayment ? (
                                'Sin inicial'
                            ) : (
                                <>
                                    Inicial <PrimaryAmount money={product.downPayment} />
                                </>
                            )}
                            {' · '}
                            {product.installmentsCount} {product.installmentsCount === 1 ? 'cuota' : 'cuotas'} de{' '}
                            <PrimaryAmount money={product.installmentAmount} />
                        </span>
                        <span className="sr-only">
                            {withoutDownPayment ? (
                                'Sin inicial, '
                            ) : (
                                <>
                                    Inicial <MoneyLabel money={product.downPayment} /> y{' '}
                                </>
                            )}
                            {product.installmentsCount} {product.installmentsCount === 1 ? 'cuota' : 'cuotas'} de{' '}
                            <MoneyLabel money={product.installmentAmount} /> {frequencyLabel(product.frequencyDays)}
                            {product.lastInstallmentAmount !== null ? (
                                <>
                                    {' '}
                                    (la última, <MoneyLabel money={product.lastInstallmentAmount} />)
                                </>
                            ) : null}
                            .
                        </span>
                    </p>
                </div>
            </article>
        </Link>
    );
}
