import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CatalogProductCard } from '@/features/catalog/components/CatalogProductCard';
import { PaymentAccountList } from '@/features/catalog/components/PaymentAccountList';
import { PlanBreakdown } from '@/features/catalog/components/PlanBreakdown';
import { ProductGallery } from '@/features/catalog/components/ProductGallery';
import { SpecificationTable } from '@/features/catalog/components/SpecificationTable';
import CatalogLayout from '@/layouts/catalog-layout';
import { type ProductShowProps } from '@/types/catalog-view';
import { Link } from '@inertiajs/react';

/**
 * La ficha del producto dentro de su catálogo (`REQ-CAT-003`): la pantalla
 * donde alguien decide comprometer dinero.
 *
 * Galería, precio y desglose del plan en bimoneda, la ficha técnica, **dónde
 * se le paga al negocio** y hasta cuatro relacionados del mismo catálogo. El
 * *toggle* de moneda del layout reproyecta todo sin petición: cada importe
 * trae ya sus dos monedas.
 *
 * ## Lo que se omite entero
 *
 * Sin ficha técnica no hay sección —ni encabezado vacío, ni «—»— (SP-5): un
 * bloque «Especificaciones» en blanco parece un fallo de carga. Sin
 * relacionados, tampoco.
 *
 * ## «Apartar» lleva al formulario
 *
 * Desde `T-045b`, al formulario de `REQ-LAY-001` con este producto y
 * `quantity = 1`. Cuando no se puede apartar, es un botón deshabilitado **con
 * su motivo escrito** al lado (RB-7) y no un enlace: no hay a dónde ir. No hay
 * telemetría de visita ni de permanencia (RB-8): es `T-084`.
 */

export default function ProductShow({ catalog, product, related }: ProductShowProps) {
    return (
        <CatalogLayout title={`${product.name} · ${catalog.name}`} business={catalog.business} showsPlatformBadge={catalog.showsPlatformBadge}>
            <nav className="mb-4 text-sm">
                <Link href={route('catalog.show', { catalog: catalog.slug })} className="link link-hover">
                    ← {catalog.name}
                </Link>
            </nav>

            <article className="grid gap-6 md:grid-cols-2">
                <ProductGallery images={product.images} name={product.name} />

                <div className="flex flex-col gap-4">
                    <header className="flex flex-col gap-1">
                        {product.categoryName !== '' ? <p className="text-sm opacity-60">{product.categoryName}</p> : null}
                        <h1 className="text-2xl font-bold">{product.name}</h1>
                        <p className="text-xs opacity-60">SKU {product.sku}</p>
                        {!product.inStock ? (
                            <div>
                                <Badge tone="warning">Agotado</Badge>
                            </div>
                        ) : null}
                    </header>

                    <PlanBreakdown product={product} />

                    <div className="flex flex-col gap-2">
                        {product.canBeReserved ? (
                            <Link
                                href={route('catalog.layaway.create', { catalog: catalog.slug, product: product.slug })}
                                className="btn btn-primary btn-block"
                            >
                                Apartar
                            </Link>
                        ) : (
                            <Button
                                variant="primary"
                                block
                                disabled
                                aria-describedby={product.unavailableReason !== null ? 'reserve-reason' : undefined}
                            >
                                Apartar
                            </Button>
                        )}
                        {product.unavailableReason !== null ? (
                            <p id="reserve-reason" className="text-sm opacity-80">
                                {product.unavailableReason}
                            </p>
                        ) : null}
                    </div>

                    <section aria-labelledby="payment-heading" className="flex flex-col gap-2">
                        <h2 id="payment-heading" className="font-semibold">
                            Formas de pago
                        </h2>
                        <PaymentAccountList accounts={product.paymentAccounts} businessName={catalog.business.name} />
                    </section>

                    {product.description !== '' ? <p className="whitespace-pre-line">{product.description}</p> : null}
                </div>
            </article>

            {product.specificationGroups.length > 0 ? (
                <section aria-labelledby="specs-heading" className="mt-8 flex flex-col gap-3">
                    <h2 id="specs-heading" className="text-lg font-semibold">
                        Ficha técnica
                    </h2>
                    <SpecificationTable groups={product.specificationGroups} />
                </section>
            ) : null}

            {related.length > 0 ? (
                <section aria-labelledby="related-heading" className="mt-8 flex flex-col gap-3">
                    <h2 id="related-heading" className="text-lg font-semibold">
                        También en este catálogo
                    </h2>
                    <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {related.map((card) => (
                            <li key={card.slug}>
                                <CatalogProductCard product={card} />
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
        </CatalogLayout>
    );
}
