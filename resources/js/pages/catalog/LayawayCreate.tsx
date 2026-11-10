import { IconChip } from '@/components/ui/icon-chip';
import { LayawaySteps } from '@/features/catalog/components/LayawaySteps';
import { LayawaySummaryCard } from '@/features/catalog/components/LayawaySummaryCard';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
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
            <div className="flex flex-col items-center gap-4 text-center">
                <LayawaySteps
                    current={2}
                    productUrl={route('catalog.product', { catalog: catalog.slug, product: product.slug })}
                    requiresDownPayment={plan?.requiresDownPayment ?? true}
                    cashSale={plan !== null && Number(plan.financedAmount.cents) === 0}
                />
                <h1 className="text-[1.75rem] leading-tight font-extrabold [font-stretch:88%] lg:text-[2.25rem]">Aparta tu producto</h1>
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
                <div className="mx-auto grid w-full max-w-[1100px] grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_420px]">
                    <aside
                        aria-label="Lo que vas a apartar"
                        className="bg-base-100 border-line flex items-center gap-3 rounded-xl border p-3 lg:hidden"
                    >
                        {product.images[0] ? <img src={product.images[0].thumb} alt="" className="size-14 shrink-0 rounded-lg object-cover" /> : null}
                        <span className="flex min-w-0 flex-col">
                            <span className="truncate text-[0.9375rem] font-bold">{product.name}</span>
                            <span className="text-ink-2 text-[0.875rem]">
                                {plan.requiresDownPayment ? (
                                    <>
                                        Hoy pagas <MoneyLabel money={plan.downPayment} className="text-base-content font-bold" />
                                    </>
                                ) : (
                                    'Sin inicial: solo cuotas'
                                )}
                            </span>
                        </span>
                    </aside>

                    <section aria-labelledby="data-heading" className="bg-base-100 border-line flex flex-col gap-4 rounded-xl border p-5">
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

                    <div className="flex flex-col gap-4 lg:sticky lg:top-6">
                        <LayawaySummaryCard
                            productName={product.name}
                            image={product.images[0] ?? null}
                            unitPrice={product.price}
                            plan={plan}
                            stale={planIsStale}
                        />
                    </div>
                </div>
            )}
        </CatalogLayout>
    );
}
