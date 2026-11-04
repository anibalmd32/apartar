import { Alert } from '@/components/ui/alert';
import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { CopyButton } from '@/features/catalog/components/CopyButton';
import { LayawaySteps } from '@/features/catalog/components/LayawaySteps';
import { catalogMention, towardsCatalog } from '@/features/catalog/lib/catalog-name';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { LayawayPlanSummary } from '@/features/layaway/components/LayawayPlanSummary';
import CatalogLayout from '@/layouts/catalog-layout';
import { type LayawayThanksProps } from '@/types/layaway';
import { cilArrowRight, cilCalendar, cilCheckCircle, cilClock } from '@coreui/icons';
import { Link, router } from '@inertiajs/react';

function keepCodeUrl(code: string, business: string): string {
    return `https://wa.me/?text=${encodeURIComponent(`Mi apartado en ${business}: ${code}. Con este código y mi cédula veo mis pagos en ${route('portal.access')}`)}`;
}

export default function LayawayThanks({ catalog, layaway, payUrl, follow }: LayawayThanksProps) {
    return (
        <CatalogLayout
            title={`Apartado ${layaway.code} · ${catalog.name}`}
            business={catalog.business}
            showsPlatformBadge={catalog.showsPlatformBadge}
            width="narrow"
        >
            <LayawaySteps current={3} requiresDownPayment={layaway.plan.requiresDownPayment} />

            <div className="bg-base-100 border-line mx-auto flex w-full max-w-[640px] flex-col gap-[18px] rounded-xl border p-5 lg:p-10">
                <header className="flex flex-col items-center gap-2 text-center">
                    <h1 className="flex items-center gap-2 text-[1.625rem] leading-tight font-extrabold [font-stretch:88%] lg:text-[2rem]">
                        <Icon icon={cilCheckCircle} size={26} className="text-success shrink-0" />
                        {layaway.plan.requiresDownPayment ? '¡Lo reservaste!' : '¡Listo, ya está apartado!'}
                    </h1>
                    <p className="text-ink-2 text-sm">
                        {layaway.productName} · {layaway.plan.quantity === 1 ? '1 unidad' : `${layaway.plan.quantity} unidades`}
                    </p>
                </header>

                <div className="bg-primary-soft flex flex-col items-center gap-1 rounded-xl p-5 text-center">
                    <p className="text-label text-ink-2">Tu código de apartado</p>
                    <div className="flex items-center gap-1.5">
                        <p
                            className="text-[26px] font-extrabold tracking-[2px] select-all lg:text-[32px]"
                            aria-label={`Código de apartado ${layaway.code}`}
                        >
                            {layaway.code}
                        </p>
                        <CopyButton
                            value={layaway.code}
                            label="el código"
                            size={18}
                            className="text-primary-ink hover:bg-base-100 inline-flex size-11 items-center justify-center rounded-xl"
                        />
                    </div>
                    <p className="text-ink-2 text-sm">Guárdalo: con él y tu cédula ves tus pagos cuando quieras.</p>
                    <a
                        href={keepCodeUrl(layaway.code, catalog.business.name)}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="btn btn-sm touch:min-h-11 mt-2"
                    >
                        Enviármelo por WhatsApp
                    </a>
                </div>

                {layaway.hasEmail ? null : (
                    <Alert tone="info">
                        <span>
                            <strong>Guarda este código ahora.</strong> No dejaste correo, así que no te llegará ninguno: envíatelo por WhatsApp o
                            hazle una captura. Con él y tu cédula podrás{' '}
                            <Link href={route('portal.access')} className="link">
                                consultar tu apartado
                            </Link>
                            .
                        </span>
                    </Alert>
                )}

                {layaway.plan.requiresDownPayment ? (
                    <section aria-labelledby="next-heading" className="border-line bg-base-100 flex items-start gap-3 rounded-2xl border px-4 py-3.5">
                        <IconChip icon={cilClock} tone="warning" size={36} className="shrink-0" />
                        <div className="flex flex-col gap-0.5">
                            <h2 id="next-heading" className="text-sm font-bold">
                                Para confirmarlo, paga la inicial de <MoneyLabel money={layaway.plan.downPayment} />
                            </h2>
                            {layaway.expiresAt !== null ? (
                                <p className="text-ink-2 text-xs">
                                    Tienes hasta el <strong>{layaway.expiresAt}</strong> para pagarla; si no llega a tiempo, el apartado se cancela y
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

                <Link href={payUrl} className="btn btn-primary btn-lg btn-block">
                    Continuar al pago
                    <Icon icon={cilArrowRight} />
                </Link>
                <p className="text-ink-3 -mt-2 text-center text-xs">
                    Entras directo a tu apartado: ahí ves las cuentas de {catalog.business.name} y subes tu comprobante.
                </p>

                {layaway.hasEmail ? <Alert tone="info">También te lo enviamos por correo, con las formas de pago.</Alert> : null}

                {follow !== null ? (
                    <Alert tone="info">
                        <span className="flex flex-wrap items-center gap-2">
                            Ahora sigues {catalogMention(catalog.name)}: verás sus novedades en tu cuenta.
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
                    <Link href={route('catalog.show', { catalog: catalog.slug })} className="hit-area text-primary-ink font-semibold hover:underline">
                        Volver {towardsCatalog(catalog.name)}
                    </Link>
                </p>
            </div>
        </CatalogLayout>
    );
}
