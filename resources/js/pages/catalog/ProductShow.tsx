import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { PaymentMethodsCard } from '@/features/catalog/components/PaymentMethodsCard';
import { ProductDetailView } from '@/features/catalog/components/ProductDetailView';
import { ProductGridCard } from '@/features/catalog/components/ProductGridCard';
import { useDwellTime } from '@/features/telemetry/hooks/useDwellTime';
import { track } from '@/features/telemetry/hooks/useTelemetry';
import CatalogLayout from '@/layouts/catalog-layout';
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

            <ProductDetailView
                product={product}
                offer={product}
                reserve={
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
                }
            >
                <PaymentMethodsCard accounts={product.paymentAccounts} businessName={catalog.business.name} />
            </ProductDetailView>

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
