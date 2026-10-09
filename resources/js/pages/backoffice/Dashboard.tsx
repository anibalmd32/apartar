import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { DashboardWarnings } from '@/features/dashboard/components/DashboardWarnings';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { formatUsage, usagePercentage } from '@/lib/plan';
import { type BackofficeSharedData } from '@/types/backoffice';
import { type DashboardMetrics, type DashboardProps } from '@/types/dashboard';
import { type UsageMetric } from '@/types/plan';
import { Link, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * El panel del negocio (`REQ-BOF-044`): qué requiere atención hoy, lo que le
 * está costando ventas o dinero, cómo va con su plan y la tasa vigente.
 *
 * Cada tarjeta lleva a la lista filtrada que la origina (RB-3), y su cifra
 * sale de la misma consulta. Sin gráficas (RB-8). Una instalación nueva ve el
 * checklist de arranque en lugar de cifras en cero (SP-1).
 */
export default function Dashboard({ metrics, links }: DashboardProps) {
    const { teamMember } = usePage<BackofficeSharedData>().props;
    const setup = metrics.setup;
    const starting = setup !== null && !(setup.paymentAccount && setup.product && setup.publishedCatalog);

    return (
        <BackofficeLayout
            title={`Hola, ${teamMember?.name.split(' ')[0] ?? ''}`.trim()}
            description={`Este es el panel de ${teamMember?.organization.name ?? 'tu negocio'}.`}
        >
            <DashboardWarnings warnings={metrics.warnings} />

            {starting ? <SetupChecklist setup={setup} /> : null}

            <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" aria-label="Cifras del día">
                <Card label="Comprobantes por revisar" href={links.receipts} value={metrics.pendingReceipts}>
                    {metrics.overdueReceiptsCount !== null && metrics.overdueReceiptsCount > 0 ? `${metrics.overdueReceiptsCount} atrasados` : null}
                </Card>
                <Card
                    label="Cobrado este mes"
                    value={metrics.collectedThisMonth === null ? null : <MoneyLabel money={metrics.collectedThisMonth} />}
                />
                <Card label="Vencen en 7 días" href={links.dueThisWeek} value={metrics.dueThisWeek?.count ?? null}>
                    {metrics.dueThisWeek !== null ? <MoneyLabel money={metrics.dueThisWeek.amount} /> : null}
                </Card>
                <Card label="Clientes en mora" href={links.customersOverdue} value={metrics.customersOverdue} />
                <Card label="Mora de más de 15 días" href={links.longOverdue} value={metrics.longOverdueLayaways} />
                <Card label="Pagados sin entregar" href={links.completedNotDelivered} value={metrics.completedNotDelivered} />
            </section>

            <PlanAndRate metrics={metrics} />
        </BackofficeLayout>
    );
}

function Card({ label, value, href, children }: { label: string; value: ReactNode; href?: string; children?: ReactNode }) {
    const body = (
        <div className="card-body gap-1">
            <span className="text-sm opacity-70">{label}</span>
            {/* SP-4 · una métrica que falló es un guion, no un cero. */}
            <span className="text-2xl font-semibold">{value ?? '—'}</span>
            {children !== null && children !== undefined ? <span className="text-sm">{children}</span> : null}
        </div>
    );

    return href !== undefined ? (
        <Link href={href} className="card bg-base-100 hover:bg-base-200 shadow-sm">
            {body}
        </Link>
    ) : (
        <div className="card bg-base-100 shadow-sm">{body}</div>
    );
}

const METRIC_LABELS: Readonly<Record<UsageMetric, string>> = {
    users: 'Usuarios',
    catalogs: 'Catálogos',
    products: 'Productos',
    active_layaways: 'Apartados activos',
    storage_mb: 'Almacenamiento (MB)',
    payment_accounts: 'Cuentas de cobro',
};

/** RB-6 · el consumo del plan, con lo que está al 80 % o más destacado; y la tasa. */
function PlanAndRate({ metrics }: { metrics: DashboardMetrics }) {
    return (
        <section className="grid gap-3 sm:grid-cols-2" aria-label="Plan y tasa">
            {metrics.plan !== null ? (
                <div className="card bg-base-100 shadow-sm">
                    <div className="card-body gap-2">
                        <h2 className="card-title text-base">Plan {metrics.plan.planName}</h2>
                        <ul className="flex flex-col gap-1 text-sm">
                            {(Object.keys(metrics.plan.usage) as UsageMetric[]).map((metric) => {
                                const usage = metrics.plan?.usage[metric];
                                const percent = usage === undefined ? null : usagePercentage(usage.used, usage.limit);

                                return usage === undefined ? null : (
                                    <li key={metric} className={percent !== null && percent >= 80 ? 'text-warning font-medium' : ''}>
                                        {METRIC_LABELS[metric]}: {formatUsage(usage.used, usage.limit)}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                </div>
            ) : null}
            <div className="card bg-base-100 shadow-sm">
                <div className="card-body gap-1 text-sm">
                    <h2 className="card-title text-base">Tasa de cambio</h2>
                    {metrics.rate !== null && metrics.rate.rate !== null ? (
                        <span>
                            {metrics.rate.sourceLabel}: Bs. {metrics.rate.rate.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                            {metrics.rate.publishedAt !== null ? ` · publicada hace ${ageOf(metrics.rate.publishedAt)}` : ''}
                            {metrics.rate.isStale ? ' (desactualizada)' : ''}
                        </span>
                    ) : (
                        <span>No hay tasa disponible: las cifras se muestran solo en dólares.</span>
                    )}
                </div>
            </div>
        </section>
    );
}

/** SP-1 · lo que falta para empezar a vender, en lugar de cifras en cero. */
/** RB-1 · la antigüedad de la tasa, en horas o, pasado un día, en días. */
function ageOf(publishedAt: string): string {
    const hours = Math.max(0, Math.floor((Date.now() - new Date(publishedAt).getTime()) / 3_600_000));

    return hours < 48 ? `${hours} h` : `${Math.floor(hours / 24)} días`;
}

function SetupChecklist({ setup }: { setup: NonNullable<DashboardMetrics['setup']> }) {
    const steps: readonly { done: boolean; label: string; href: string }[] = [
        { done: setup.paymentAccount, label: 'Añade una cuenta de cobro', href: '/backoffice/cuentas-de-cobro' },
        { done: setup.product, label: 'Carga tu primer producto', href: '/backoffice/productos/crear' },
        { done: setup.publishedCatalog, label: 'Publica tu primer catálogo', href: '/backoffice/catalogos' },
    ];

    return (
        <section className="card bg-base-100 shadow-sm" aria-label="Para empezar">
            <div className="card-body gap-2">
                <h2 className="card-title text-base">Para empezar a vender</h2>
                <ol className="flex flex-col gap-1 text-sm">
                    {steps.map((step) => (
                        <li key={step.label}>
                            {step.done ? (
                                <span className="line-through opacity-60">{step.label}</span>
                            ) : (
                                <Link href={step.href} className="link">
                                    {step.label}
                                </Link>
                            )}
                        </li>
                    ))}
                </ol>
            </div>
        </section>
    );
}
