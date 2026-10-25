import { type ChipTone, IconChip } from '@/components/ui/icon-chip';
import { SectionCard } from '@/components/ui/section-card';
import { PlansLink } from '@/features/plan/components/PlansLink';
import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import { type DashboardWarning } from '@/types/dashboard';
import { type UsageMetric } from '@/types/plan';
import { cilBan, cilBank, cilBasket, cilEnvelopeClosed, cilLibrary, cilReload, cilWarning } from '@coreui/icons';
import { Link } from '@inertiajs/react';

const LOOK: Record<DashboardWarning['kind'], { icon: string[]; tone: ChipTone }> = {
    no_active_payment_account: { icon: cilBank, tone: 'error' },
    published_catalog_empty: { icon: cilLibrary, tone: 'warning' },
    product_published_in_no_catalog: { icon: cilBasket, tone: 'info' },
    stale_exchange_rate: { icon: cilReload, tone: 'warning' },
    plan_limit_blocking_sales: { icon: cilBan, tone: 'error' },
    invoice_overdue: { icon: cilWarning, tone: 'error' },
    email_not_verified: { icon: cilEnvelopeClosed, tone: 'info' },
};
export function DashboardWarnings({ warnings }: { warnings: readonly DashboardWarning[] }) {
    if (warnings.length === 0) {
        return null;
    }

    return (
        <SectionCard title="Avisos">
            <ul className="flex flex-col gap-3">
                {warnings.map((warning, index) => (
                    <li key={`${warning.kind}-${index}`} className="flex items-start gap-3 text-[13px] leading-snug">
                        <IconChip icon={LOOK[warning.kind].icon} tone={LOOK[warning.kind].tone} />
                        <span className="text-ink-2 [&_a]:text-primary-ink min-w-0 pt-1.5 [&_a]:font-semibold">
                            <WarningText warning={warning} />
                        </span>
                    </li>
                ))}
            </ul>
        </SectionCard>
    );
}

function LostSales({ metric }: { metric: UsageMetric }) {
    const plan = usePlanLimits();

    return (
        <span>
            Esta semana tu plan rechazó apartados de tus clientes: llegaste al techo de {plan.metricLabel(metric)}. <PlansLink className="link" />
        </span>
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
            return <LostSales metric={warning.metric} />;
        case 'invoice_overdue':
            return (
                <span>
                    Tu suscripción tiene una factura vencida{warning.dueDate !== null ? ` desde el ${formatDay(warning.dueDate)}` : ''}.{' '}
                    <Link href="/backoffice/suscripcion/facturas" className="link">
                        Págala
                    </Link>
                </span>
            );
        case 'email_not_verified':
            return <span>El correo del dueño del negocio no está verificado: no podrá recuperar la cuenta ni recibir avisos.</span>;
    }
}
