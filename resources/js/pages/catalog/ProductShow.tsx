import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { PaymentMethodsCard } from '@/features/catalog/components/PaymentMethodsCard';
import { PlanBreakdown, PriceTag } from '@/features/catalog/components/PlanBreakdown';
import { ProductGallery } from '@/features/catalog/components/ProductGallery';
import { ProductGridCard } from '@/features/catalog/components/ProductGridCard';
import { useDwellTime } from '@/features/telemetry/hooks/useDwellTime';
import { track } from '@/features/telemetry/hooks/useTelemetry';
import CatalogLayout from '@/layouts/catalog-layout';
import { richTextContentClass } from '@/lib/rich-text';
import { cn } from '@/lib/utils';
import { type ProductShowProps } from '@/types/catalog-view';
import { cilCart, cilChevronRight } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { useEffect } from 'react';

export default function ProductShow({ catalog, product, related }: ProductShowProps) {
    useEffect(() => {
        track('product_viewed', { catalogSlug: catalog.slug, subjectSlug: product.slug, payload: { source: 'catalog' } });
    }, [catalog.slug, product.slug]);
    useDwellTime({
        path: route('catalog.product', { catalog: catalog.slug, product: product.slug }, false),
        catalogSlug: catalog.slug,
        subjectSlug: product.slug,
    });

    const hasDetails = product.description !== '' || product.specifications.length > 0;

    return (
        <CatalogLayout title={`${product.name} · ${catalog.name}`} business={catalog.business} showsPlatformBadge={catalog.showsPlatformBadge}>
            <nav aria-label="Ruta" className="flex min-w-0 items-center gap-1.5 text-[13px]">
                <Link href={route('catalog.show', { catalog: catalog.slug })} className="text-primary-ink shrink-0 font-semibold hover:underline">
                    {catalog.name}
                </Link>
                <Icon icon={cilChevronRight} size={12} className="text-ink-3" />
                <span className="text-ink-3 truncate" aria-current="page">
                    {product.name}
                </span>
            </nav>

            <article className="grid grid-cols-1 gap-6 lg:grid-cols-[620fr_612fr] lg:gap-12">
                <ProductGallery images={product.images} name={product.name} />

                <div className="flex min-w-0 flex-col gap-[18px]">
                    <header className="flex flex-col gap-2">
                        <p className="text-ink-3 text-[13px]">
                            {product.categoryName !== '' ? `${product.categoryName} · ` : null}SKU {product.sku}
                        </p>
                        <h1 className="text-[26px] leading-[1.3] font-extrabold tracking-[-1px] lg:text-[34px]">{product.name}</h1>
                    </header>

                    <PriceTag product={product} />
                    <PlanBreakdown product={product} />

                    <div className="flex flex-col gap-2">
                        {product.canBeReserved ? (
                            <Link
                                href={route('catalog.layaway.create', { catalog: catalog.slug, product: product.slug })}
                                className="btn btn-primary btn-lg btn-block"
                            >
                                <Icon icon={cilCart} />
                                Apartar ahora
                            </Link>
                        ) : (
                            <Button
                                variant="primary"
                                size="lg"
                                block
                                disabled
                                aria-describedby={product.unavailableReason !== null ? 'reserve-reason' : undefined}
                            >
                                <Icon icon={cilCart} />
                                Apartar ahora
                            </Button>
                        )}
                        {product.unavailableReason !== null ? (
                            <p id="reserve-reason" className="text-ink-2 text-sm">
                                {product.unavailableReason}
                            </p>
                        ) : null}
                    </div>

                    <p className="text-ink-2 flex items-center gap-2 text-[13px] font-semibold">
                        <span aria-hidden="true" className={cn('size-2 rounded-full', product.inStock ? 'bg-success' : 'bg-error')} />
                        {product.inStock ? 'Disponible' : 'Agotado'}
                        {product.inStock && product.stockLeft ? (
                            <span className="text-ink-3 font-normal">
                                · {product.stockLeft === 1 ? 'queda 1 unidad' : `quedan ${product.stockLeft} unidades`}
                            </span>
                        ) : null}
                    </p>

                    <PaymentMethodsCard accounts={product.paymentAccounts} businessName={catalog.business.name} />
                </div>
            </article>

            {hasDetails ? (
                <div className="bg-base-100 border-line shadow-card flex flex-col gap-4 rounded-2xl border p-5">
                    {product.description !== '' ? (
                        <section aria-labelledby="description-heading" className="flex flex-col gap-4">
                            <h2 id="description-heading" className="text-[17px] font-bold">
                                Descripción
                            </h2>
                            <div
                                className={cn('text-ink-2 text-[15px] leading-relaxed', richTextContentClass)}
                                dangerouslySetInnerHTML={{ __html: product.description }}
                            />
                        </section>
                    ) : null}

                    {product.specifications.length > 0 ? (
                        <section aria-labelledby="specs-heading" className="flex flex-col gap-4">
                            <h2 id="specs-heading" className="text-[17px] font-bold">
                                Características
                            </h2>
                            <ul className="text-ink-2 flex list-disc flex-col gap-1.5 pl-5 text-[15px] leading-relaxed">
                                {product.specifications.map((specification, index) => (
                                    <li key={`${index}-${specification}`}>{specification}</li>
                                ))}
                            </ul>
                        </section>
                    ) : null}
                </div>
            ) : null}

            {related.length > 0 ? (
                <section aria-labelledby="related-heading" className="flex flex-col gap-4">
                    <h2 id="related-heading" className="text-xl font-bold">
                        También te puede gustar
                    </h2>
                    <ul className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4 lg:gap-5">
                        {related.map((card) => (
                            <li key={card.slug}>
                                <ProductGridCard product={card} />
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
        </CatalogLayout>
    );
}
