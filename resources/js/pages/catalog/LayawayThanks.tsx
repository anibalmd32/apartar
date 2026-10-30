import { Alert } from '@/components/ui/alert';
import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { CopyButton } from '@/features/catalog/components/CopyButton';
import { PaymentMethodsCard } from '@/features/catalog/components/PaymentMethodsCard';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { LayawayPlanSummary } from '@/features/layaway/components/LayawayPlanSummary';
import CatalogLayout from '@/layouts/catalog-layout';
import { type LayawayThanksProps } from '@/types/layaway';
import { cilCalendar, cilCheckCircle, cilClock, cilCloudUpload } from '@coreui/icons';
import { Link, router } from '@inertiajs/react';

export default function LayawayThanks({ catalog, layaway, paymentAccounts, receiptUrl, follow }: LayawayThanksProps) {
    return (
        <CatalogLayout
            title={`Apartado ${layaway.code} · ${catalog.name}`}
            business={catalog.business}
            showsPlatformBadge={catalog.showsPlatformBadge}
            width="narrow"
        >
            <div className="bg-base-100 border-line shadow-card mx-auto flex w-full max-w-[640px] flex-col gap-[18px] rounded-2xl border p-5 lg:p-10">
                <header className="flex flex-col items-center gap-2 text-center">
                    <IconChip icon={cilCheckCircle} tone="success" size={64} className="mb-2 rounded-2xl" />
                    <h1 className="text-[24px] leading-[1.3] font-extrabold tracking-[-1px] lg:text-[30px]">¡Listo, ya está apartado!</h1>
                    <p className="text-ink-2 text-sm">
                        {layaway.productName} · {layaway.plan.quantity === 1 ? '1 unidad' : `${layaway.plan.quantity} unidades`}
                    </p>
                </header>

                <div className="bg-primary-soft flex flex-col items-center gap-1 rounded-2xl p-5 text-center">
                    <p className="text-eyebrow text-primary-ink">Tu código de apartado</p>
                    <div className="flex items-center gap-1.5">
                        <p
                            className="text-[26px] font-extrabold tracking-[2px] select-all lg:text-[32px]"
                            aria-label={`Código de apartado ${layaway.code}`}
                        >
                            {layaway.code}
                        </p>
                        <CopyButton value={layaway.code} label="el código" size={18} className="text-primary-ink hover:bg-base-100" />
                    </div>
                </div>

                {layaway.hasEmail ? null : (
                    <Alert tone="warning">
                        <span>
                            <strong>Anota este código ahora.</strong> No dejaste correo, así que no te llegará ninguno: esta pantalla es el único
                            sitio donde aparece. Con él y tu cédula podrás{' '}
                            <Link href={route('portal.access')} className="link">
                                consultar tu apartado
                            </Link>
                            .
                        </span>
                    </Alert>
                )}

                {layaway.plan.requiresDownPayment ? (
                    <section aria-labelledby="next-heading" className="bg-warning-soft flex items-start gap-3 rounded-2xl px-4 py-3.5">
                        <Icon icon={cilClock} size={20} className="text-warning mt-0.5" />
                        <div className="flex flex-col gap-0.5">
                            <h2 id="next-heading" className="text-sm font-bold">
                                Para confirmarlo, paga la inicial de <MoneyLabel money={layaway.plan.downPayment} />
                            </h2>
                            {layaway.expiresAt !== null ? (
                                <p className="text-ink-2 text-xs">
                                    Tienes hasta el <strong>{layaway.expiresAt}</strong>. Si no llega el pago de la inicial, el apartado se cancela y
                                    la unidad vuelve a estar a la venta.
                                </p>
                            ) : null}
                        </div>
                    </section>
                ) : (
                    <section aria-labelledby="next-heading" className="bg-success-soft flex items-start gap-3 rounded-2xl px-4 py-3.5">
                        <Icon icon={cilCalendar} size={20} className="text-success mt-0.5" />
                        <div className="flex flex-col gap-0.5">
                            <h2 id="next-heading" className="text-sm font-bold">
                                No lleva inicial: tu apartado ya está activo
                            </h2>
                            <p className="text-ink-2 text-xs">
                                Lo pagas solo en cuotas.
                                {layaway.firstDueDate !== null ? (
                                    <>
                                        {' '}
                                        La primera vence el <strong>{layaway.firstDueDate}</strong>.
                                    </>
                                ) : null}
                            </p>
                        </div>
                    </section>
                )}

                <details className="border-line rounded-2xl border [&_section]:rounded-none [&_section]:bg-transparent">
                    <summary className="cursor-pointer px-4 py-3 text-sm font-semibold">Ver tu plan de pago completo</summary>
                    <LayawayPlanSummary plan={layaway.plan} />
                </details>

                <PaymentMethodsCard accounts={paymentAccounts} businessName={catalog.business.name} />

                {receiptUrl !== null ? (
                    <Link href={receiptUrl} className="btn btn-primary btn-lg btn-block">
                        <Icon icon={cilCloudUpload} />
                        Subir el comprobante de pago
                    </Link>
                ) : null}

                <Link href={route('portal.access')} className="btn btn-lg btn-block">
                    Ver mi apartado
                </Link>

                {layaway.hasEmail ? <Alert tone="info">También te lo enviamos por correo, con las formas de pago.</Alert> : null}

                {follow !== null ? (
                    <Alert tone="info">
                        <span className="flex flex-wrap items-center gap-2">
                            Ahora sigues {catalog.name}: verás sus novedades en tu cuenta.
                            <button
                                type="button"
                                className="btn btn-ghost btn-xs"
                                onClick={() => router.delete(follow.unfollowUrl, { preserveScroll: true })}
                            >
                                Dejar de seguir
                            </button>
                        </span>
                    </Alert>
                ) : null}

                <p className="text-center text-sm">
                    <Link href={route('catalog.show', { catalog: catalog.slug })} className="text-primary-ink font-semibold hover:underline">
                        Volver a {catalog.name}
                    </Link>
                </p>
            </div>
        </CatalogLayout>
    );
}
