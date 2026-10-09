import { Alert } from '@/components/ui/alert';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import { type DashboardWarning } from '@/types/dashboard';
import { Link } from '@inertiajs/react';

/**
 * Los avisos del panel (`REQ-BOF-044` RB-4), cada uno con lo que hay que hacer
 * y dónde. **Sin avisos, la sección no existe** (SP-6): no se inventan para
 * llenar espacio.
 */
export function DashboardWarnings({ warnings }: { warnings: readonly DashboardWarning[] }) {
    if (warnings.length === 0) {
        return null;
    }

    return (
        <section className="flex flex-col gap-2" aria-label="Avisos">
            {warnings.map((warning, index) => (
                <Alert key={`${warning.kind}-${index}`} tone={warning.kind === 'invoice_overdue' ? 'error' : 'warning'}>
                    <WarningText warning={warning} />
                </Alert>
            ))}
        </section>
    );
}

function WarningText({ warning }: { warning: DashboardWarning }) {
    switch (warning.kind) {
        case 'no_active_payment_account':
            return (
                <span>
                    No tienes ninguna cuenta de cobro activa: tus clientes no saben adónde pagarte.{' '}
                    <Link href="/backoffice/cuentas-de-cobro" className="link">
                        Añade una
                    </Link>
                </span>
            );
        case 'published_catalog_empty':
            return (
                <span>
                    El catálogo «{warning.catalogSlug}» está publicado y no tiene ningún producto a la venta.{' '}
                    <Link href="/backoffice/catalogos" className="link">
                        Revisa tus catálogos
                    </Link>
                </span>
            );
        case 'product_published_in_no_catalog':
            return (
                <span>
                    {warning.count === 1 ? 'Un producto publicado no está' : `${warning.count} productos publicados no están`} en ningún catálogo:
                    nadie los ve.{' '}
                    <Link href="/backoffice/catalogos" className="link">
                        Añádelos a uno
                    </Link>
                </span>
            );
        case 'stale_exchange_rate':
            return <span>La tasa de cambio tiene {warning.ageHours} horas: revisa que tus precios en bolívares sigan siendo los correctos.</span>;
        case 'plan_limit_blocking_sales':
            return <span>Un límite de tu plan está rechazando apartados.</span>;
        case 'invoice_overdue':
            return (
                <span>
                    Tu suscripción tiene una factura vencida{warning.dueDate !== null ? ` desde el ${formatDay(warning.dueDate)}` : ''}.{' '}
                    <Link href="/backoffice/suscripcion" className="link">
                        Regulariza el pago
                    </Link>
                </span>
            );
        case 'email_not_verified':
            return <span>El correo del dueño del negocio no está verificado: no podrá recuperar la cuenta ni recibir avisos.</span>;
    }
}
