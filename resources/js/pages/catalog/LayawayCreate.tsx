import { IconChip } from '@/components/ui/icon-chip';
import { LayawaySteps } from '@/features/catalog/components/LayawaySteps';
import { LayawaySummaryCard } from '@/features/catalog/components/LayawaySummaryCard';
import { PaymentMethodsCard } from '@/features/catalog/components/PaymentMethodsCard';
import { LayawayForm } from '@/features/layaway/components/LayawayForm';
import { useLayawayForm } from '@/features/layaway/hooks/useLayawayForm';
import { track } from '@/features/telemetry/hooks/useTelemetry';
import CatalogLayout from '@/layouts/catalog-layout';
import { type LayawayCreateProps } from '@/types/layaway';
import { cilBan } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { useEffect } from 'react';

export default function LayawayCreate({ catalog, product, plan, quantity, consent, account }: LayawayCreateProps) {
    const form = useLayawayForm({
        initialQuantity: quantity,
        maxQuantity: product.maxQuantity,
        storeUrl: route('catalog.layaway.store', { catalog: catalog.slug, product: product.slug }),
        prefill: account,
    });

    useEffect(() => {
        track('layaway_form_started', { catalogSlug: catalog.slug, subjectSlug: product.slug, payload: { source: 'catalog' } });
    }, [catalog.slug, product.slug]);

    const planIsStale = plan !== null && plan.quantity !== form.data.quantity;

    return (
        <CatalogLayout
            title={`Apartar ${product.name} · ${catalog.name}`}
            business={catalog.business}
            showsPlatformBadge={catalog.showsPlatformBadge}
        >
            <div className="flex flex-col gap-4">
                <LayawaySteps current={2} productUrl={route('catalog.product', { catalog: catalog.slug, product: product.slug })} />
                <h1 className="text-[26px] leading-[1.3] font-extrabold tracking-[-1px] lg:text-[34px]">Aparta tu producto</h1>
            </div>

            {!product.canBeReserved || plan === null ? (
                <div className="bg-base-100 border-line shadow-card flex flex-col items-center gap-3 rounded-2xl border px-6 py-10 text-center">
                    <IconChip icon={cilBan} tone="neutral" size={48} />
                    <p className="text-h2">{product.unavailableReason ?? 'Este producto no se puede apartar ahora mismo.'}</p>
                    <Link href={route('catalog.product', { catalog: catalog.slug, product: product.slug })} className="btn">
                        Volver a {product.name}
                    </Link>
                </div>
            ) : (
                <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_420px]">
                    <section
                        aria-labelledby="data-heading"
                        className="bg-base-100 border-line shadow-card flex flex-col gap-4 rounded-2xl border p-5"
                    >
                        <div className="flex flex-col gap-2">
                            <h2 id="data-heading" className="text-[22px] font-bold">
                                Tus datos
                            </h2>
                            <p className="text-ink-2 text-sm">
                                No necesitas crear una cuenta. Con tu cédula y el código del apartado podrás ver tus pagos.
                            </p>
                        </div>
                        <LayawayForm form={form} maxQuantity={product.maxQuantity} consent={consent} emailLocked={account !== null} />
                    </section>

                    <div className="flex flex-col gap-4 max-lg:row-start-1">
                        <LayawaySummaryCard
                            productName={product.name}
                            image={product.images[0] ?? null}
                            unitPrice={product.price}
                            plan={plan}
                            stale={planIsStale}
                        />
                        <PaymentMethodsCard accounts={product.paymentAccounts} businessName={catalog.business.name} />
                    </div>
                </div>
            )}
        </CatalogLayout>
    );
}
