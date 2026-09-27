import { PaymentAccountList } from '@/features/catalog/components/PaymentAccountList';
import { LayawayForm } from '@/features/layaway/components/LayawayForm';
import { LayawayPlanSummary } from '@/features/layaway/components/LayawayPlanSummary';
import { useLayawayForm } from '@/features/layaway/hooks/useLayawayForm';
import CatalogLayout from '@/layouts/catalog-layout';
import { type LayawayCreateProps } from '@/types/layaway';
import { Link } from '@inertiajs/react';

/**
 * El formulario de apartado (`REQ-LAY-001`): donde alguien con un enlace se
 * convierte en cliente.
 *
 * Llega desde «Apartar» en la ficha, con el producto y `quantity = 1`. Enseña
 * el plan del apartado en bimoneda —recalculado en el servidor al cambiar la
 * cantidad— y **dónde se le paga al negocio**, antes de pedir nada: quien va a
 * apartar tiene que saber si puede pagar por Pago móvil o solo en efectivo.
 *
 * Si el producto ya no se puede apartar —agotado, sin plan—, no hay
 * formulario: se dice el motivo, como en la ficha, y se ofrece volver.
 */

export default function LayawayCreate({ catalog, product, plan, quantity, consent, recognisedAreaCodes }: LayawayCreateProps) {
    const form = useLayawayForm({
        initialQuantity: quantity,
        maxQuantity: product.maxQuantity,
        recognisedAreaCodes,
        storeUrl: route('catalog.layaway.store', { catalog: catalog.slug, product: product.slug }),
    });

    const planIsStale = plan !== null && plan.quantity !== form.data.quantity;

    return (
        <CatalogLayout
            title={`Apartar ${product.name} · ${catalog.name}`}
            business={catalog.business}
            showsPlatformBadge={catalog.showsPlatformBadge}
        >
            <nav className="mb-4 text-sm">
                <Link href={route('catalog.product', { catalog: catalog.slug, product: product.slug })} className="link link-hover">
                    ← {product.name}
                </Link>
            </nav>

            <header className="mb-4 flex flex-col gap-1">
                <h1 className="text-2xl font-bold">Apartar {product.name}</h1>
                <p className="text-sm opacity-70">Sin registrarte: con tus datos y el pago de la inicial.</p>
            </header>

            {!product.canBeReserved || plan === null ? (
                <p className="bg-base-100 rounded-box p-4">{product.unavailableReason ?? 'Este producto no se puede apartar ahora mismo.'}</p>
            ) : (
                <div className="grid gap-6 md:grid-cols-2">
                    <div className="flex flex-col gap-4">
                        <LayawayPlanSummary plan={plan} stale={planIsStale} />

                        <section aria-labelledby="payment-heading" className="flex flex-col gap-2">
                            <h2 id="payment-heading" className="font-semibold">
                                Cómo le pagarás a {catalog.business.name}
                            </h2>
                            <PaymentAccountList accounts={product.paymentAccounts} businessName={catalog.business.name} />
                        </section>
                    </div>

                    <LayawayForm form={form} maxQuantity={product.maxQuantity} consent={consent} />
                </div>
            )}
        </CatalogLayout>
    );
}
