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

function shortFrequency(days: number): string {
    return days === 1 ? 'c/día' : `c/${days} días`;
}

export function ProductGridCard({ product }: { product: ProductCard }) {
    const withoutDownPayment = Number(product.downPayment.cents) === 0;

    return (
        <Link
            href={route('catalog.product', { catalog: product.catalogSlug, product: product.slug })}
            className="group focus-visible:outline-primary block h-full rounded-2xl focus-visible:outline-2 focus-visible:outline-offset-2"
        >
            <article className="bg-base-100 border-line shadow-card group-hover:shadow-pop flex h-full flex-col overflow-hidden rounded-2xl border transition-shadow">
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
                    <h2 className="line-clamp-2 text-sm leading-snug font-semibold">{product.name}</h2>

                    <div className="flex items-start gap-1.5">
                        <MoneyLabel money={product.price} stacked className="text-[17px] leading-6 font-extrabold tracking-[-0.3px]" />
                        {product.compareAtPrice !== null ? (
                            <s className="text-ink-3 mt-1 text-xs">
                                <span className="sr-only">Antes </span>
                                <PrimaryAmount money={product.compareAtPrice} />
                            </s>
                        ) : null}
                    </div>

                    <p className="bg-primary-soft text-primary-ink mt-auto flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-[11px] font-semibold">
                        <Icon icon={cilCalendar} size={12} />
                        <span aria-hidden="true" className="truncate">
                            {withoutDownPayment ? null : (
                                <>
                                    <PrimaryAmount money={product.downPayment} /> +{' '}
                                </>
                            )}
                            {product.installmentsCount} × <PrimaryAmount money={product.installmentAmount} />
                            <span className="max-sm:hidden"> · {shortFrequency(product.frequencyDays)}</span>
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
