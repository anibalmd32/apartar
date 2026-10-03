import { Alert } from '@/components/ui/alert';
import { PaymentAccountList } from '@/features/catalog/components/PaymentAccountList';
import { LayawayPlanSummary } from '@/features/layaway/components/LayawayPlanSummary';
import CatalogLayout from '@/layouts/catalog-layout';
import { type LayawayThanksProps } from '@/types/layaway';
import { Link } from '@inertiajs/react';

/**
 * La confirmación del apartado (`REQ-LAY-001` RB-10, happy path paso 5).
 *
 * Tres cosas y en este orden, porque es el orden en que el cliente las
 * necesita: **el código**, bien visible y fácil de copiar —es media credencial
 * del portal y lo va a dictar por teléfono—; **cuánto pagar y hasta cuándo**;
 * y **dónde pagarle al negocio**.
 *
 * ## Sin correo, esta pantalla es el único canal
 *
 * Y lo dice (RB-10): no llegará ningún correo, y quien cierre esta pestaña sin
 * anotar el código pierde el acceso a su apartado hasta que llame al negocio.
 * Con correo, se dice que el código también va por ahí.
 *
 * El botón de subir el comprobante es de `T-059`: hasta entonces `receiptUrl`
 * llega nulo y no se pinta un botón que no lleva a ninguna parte.
 */

export default function LayawayThanks({ catalog, layaway, paymentAccounts, receiptUrl }: LayawayThanksProps) {
    return (
        <CatalogLayout
            title={`Apartado ${layaway.code} · ${catalog.name}`}
            business={catalog.business}
            showsPlatformBadge={catalog.showsPlatformBadge}
        >
            <div className="mx-auto flex max-w-2xl flex-col gap-6">
                <header className="flex flex-col items-center gap-2 text-center">
                    <h1 className="text-2xl font-bold">¡Listo, está apartado!</h1>
                    <p className="opacity-80">{layaway.productName}</p>
                    <p className="text-sm opacity-70">Tu código de apartado</p>
                    <p className="font-mono text-3xl font-bold tracking-widest select-all" aria-label={`Código de apartado ${layaway.code}`}>
                        {layaway.code}
                    </p>
                </header>

                {layaway.hasEmail ? (
                    <Alert tone="info">También te lo enviamos por correo, con las formas de pago.</Alert>
                ) : (
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

                <section aria-labelledby="next-heading" className="flex flex-col gap-2">
                    <h2 id="next-heading" className="font-semibold">
                        Para confirmarlo, paga la inicial
                    </h2>
                    {layaway.expiresAt !== null ? (
                        <p className="text-sm">
                            Tienes hasta el <strong>{layaway.expiresAt}</strong>. Si no llega el pago de la inicial, el apartado se cancela y la
                            unidad vuelve a estar a la venta.
                        </p>
                    ) : null}
                    <LayawayPlanSummary plan={layaway.plan} />
                </section>

                <section aria-labelledby="payment-heading" className="flex flex-col gap-2">
                    <h2 id="payment-heading" className="font-semibold">
                        Cómo pagarle a {catalog.business.name}
                    </h2>
                    <PaymentAccountList accounts={paymentAccounts} businessName={catalog.business.name} />
                </section>

                {receiptUrl !== null ? (
                    <Link href={receiptUrl} className="btn btn-primary btn-block">
                        Subir el comprobante de pago
                    </Link>
                ) : null}

                <p className="text-center text-sm">
                    <Link href={route('catalog.show', { catalog: catalog.slug })} className="link link-hover">
                        Volver a {catalog.name}
                    </Link>
                </p>
            </div>
        </CatalogLayout>
    );
}
