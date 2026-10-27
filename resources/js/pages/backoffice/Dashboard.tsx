import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { Meter } from '@/components/ui/meter';
import { SectionCard } from '@/components/ui/section-card';
import { StatCard } from '@/components/ui/stat-card';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { DashboardWarnings } from '@/features/dashboard/components/DashboardWarnings';
import { PendingReceiptsCard } from '@/features/dashboard/components/PendingReceiptsCard';
import { HowItWorks, SetupChecklist } from '@/features/dashboard/components/SetupChecklist';
import { SuspensionCard } from '@/features/dashboard/components/SuspensionCard';
import { LimitBanner } from '@/features/plan/components/LimitBanner';
import { PlansLink } from '@/features/plan/components/PlansLink';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { formatUsage, usagePercentage } from '@/lib/plan';
import { type BackofficeSharedData } from '@/types/backoffice';
import { type DashboardMetrics, type DashboardProps } from '@/types/dashboard';
import { type CurrencyPageProps } from '@/types/exchange';
import { type UsageMetric } from '@/types/plan';
import { cilAvTimer, cilCalendar, cilCash, cilChatBubble, cilDescription, cilPlus, cilSwapHorizontal, cilTruck, cilWarning } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';

export default function Dashboard({ metrics, links, pendingReceipts, suspension = null, longOverdueDays = 15 }: DashboardProps) {
    const { teamMember, currency } = usePage<BackofficeSharedData & { currency?: CurrencyPageProps }>().props;
    const setup = metrics.setup;
    const starting = setup !== null && !(setup.paymentAccount && setup.product && setup.publishedCatalog);
    const timezone = currency?.timezone;
    const firstName = teamMember?.name.split(' ')[0] ?? '';

    return (
        <BackofficeLayout
            title={`${greeting(timezone)}${firstName ? `, ${firstName}` : ''}`}
            description={`${today(timezone)} · esto es lo que necesita tu atención hoy.`}
            actions={
                teamMember?.capabilities?.includes('products') ? (
                    <Link href="/backoffice/productos/crear" className="btn btn-primary">
                        <Icon icon={cilPlus} />
                        Nuevo producto
                    </Link>
                ) : null
            }
        >
            <LimitBanner metric="active_layaways" />

            {suspension !== null ? <SuspensionCard suspension={suspension} invoicesUrl={links.invoices ?? null} /> : null}

            {starting ? (
                <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_360px] lg:gap-5">
                    <SetupChecklist setup={setup} />
                    <HowItWorks />
                </div>
            ) : null}

            <section className="grid grid-cols-2 gap-2.5 lg:grid-cols-3 lg:gap-4" aria-label="Cifras del día">
                <StatCard label="Comprobantes por revisar" icon={cilDescription} tone="primary" href={links.receipts} value={metrics.pendingReceipts}>
                    {metrics.overdueReceiptsCount !== null && metrics.overdueReceiptsCount > 0 ? (
                        <>
                            <span className="badge badge-error badge-count">{metrics.overdueReceiptsCount}</span> con más de 48 h
                        </>
                    ) : null}
                </StatCard>
                <StatCard
                    label="Cobrado este mes"
                    icon={cilCash}
                    tone="success"
                    value={metrics.collectedThisMonth === null ? null : <MoneyLabel money={metrics.collectedThisMonth} stacked />}
                />
                <StatCard label="Vencen en 7 días" icon={cilCalendar} tone="info" href={links.dueThisWeek} value={metrics.dueThisWeek?.count ?? null}>
                    {metrics.dueThisWeek !== null ? (
                        <>
                            Por cobrar <MoneyLabel money={metrics.dueThisWeek.amount} compact />
                        </>
                    ) : null}
                </StatCard>
                <StatCard label="Clientes en mora" icon={cilWarning} tone="error" href={links.customersOverdue} value={metrics.customersOverdue} />
                <StatCard label="Mora prolongada" icon={cilAvTimer} tone="warning" href={links.longOverdue} value={metrics.longOverdueLayaways}>
                    {metrics.longOverdueLayaways
                        ? 'Requieren seguimiento'
                        : `Más de ${longOverdueDays} ${longOverdueDays === 1 ? 'día' : 'días'} tras la gracia`}
                </StatCard>
                <StatCard
                    label="Pagados sin entregar"
                    icon={cilTruck}
                    tone="neutral"
                    href={links.completedNotDelivered}
                    value={metrics.completedNotDelivered}
                >
                    {metrics.completedNotDelivered ? 'Listos para entregar' : null}
                </StatCard>
                <StatCard label="Chats sin leer" icon={cilChatBubble} tone="primary" href="/backoffice/chat" value={metrics.unreadMessages} />
            </section>

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_360px] lg:gap-5">
                {pendingReceipts !== undefined && pendingReceipts !== null && pendingReceipts.length > 0 ? (
                    <PendingReceiptsCard receipts={pendingReceipts} inboxUrl={links.receipts} />
                ) : null}
                <div className={pendingReceipts?.length ? 'flex flex-col gap-4' : 'grid gap-4 lg:col-span-2 lg:grid-cols-2 lg:gap-5'}>
                    <PlanAndRate metrics={metrics} />
                    <DashboardWarnings warnings={metrics.warnings} />
                </div>
            </div>
        </BackofficeLayout>
    );
}

function greeting(timezone: string | undefined): string {
    const hour = Number(new Intl.DateTimeFormat('es-VE', { hour: 'numeric', hourCycle: 'h23', timeZone: timezone }).format(new Date()));

    return hour < 12 ? 'Buenos días' : hour < 19 ? 'Buenas tardes' : 'Buenas noches';
}

function today(timezone: string | undefined): string {
    const text = new Intl.DateTimeFormat('es-VE', { weekday: 'long', day: 'numeric', month: 'long', timeZone: timezone }).format(new Date());

    return text.charAt(0).toUpperCase() + text.slice(1);
}

function PlanAndRate({ metrics }: { metrics: DashboardMetrics }) {
    const plan = metrics.plan;

    return (
        <SectionCard
            title={plan !== null ? `Plan ${plan.planName}` : 'Tasa de cambio'}
            aria-label="Plan y tasa"
            action={plan !== null ? <PlansLink className="hover:underline" /> : null}
        >
            {plan !== null ? (
                <ul className="flex flex-col gap-3.5">
                    {(Object.keys(plan.usage) as UsageMetric[]).map((metric) => {
                        const usage = plan.usage[metric];
                        const percent = usage === undefined ? null : usagePercentage(usage.used, usage.limit);

                        return usage === undefined ? null : (
                            <Meter
                                key={metric}
                                as="li"
                                label={plan.metricLabels[metric] ?? metric}
                                value={formatUsage(usage.used, usage.limit)}
                                percentage={percent}
                                warn={percent !== null && percent >= 80}
                            />
                        );
                    })}
                </ul>
            ) : null}

            <div className="bg-surface-2 flex items-center gap-3 rounded-xl p-3">
                <IconChip icon={cilSwapHorizontal} tone={metrics.rate?.isStale ? 'warning' : 'success'} size={34} />
                {metrics.rate !== null && metrics.rate.rate !== null ? (
                    <div className="flex min-w-0 flex-col">
                        <span className="text-sm font-bold">
                            Bs. {metrics.rate.rate.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} por USD
                        </span>
                        <span className="text-ink-3 text-xs">
                            Fuente {metrics.rate.sourceLabel}
                            {metrics.rate.publishedAt !== null ? ` · actualizada hace ${ageOf(metrics.rate.publishedAt)}` : ''}
                            {metrics.rate.isStale ? ' (desactualizada)' : ''}
                        </span>
                    </div>
                ) : (
                    <span className="text-ink-2 text-[13px]">No hay tasa disponible: las cifras se muestran solo en dólares.</span>
                )}
            </div>
        </SectionCard>
    );
}

function ageOf(publishedAt: string): string {
    const hours = Math.max(0, Math.floor((Date.now() - new Date(publishedAt).getTime()) / 3_600_000));

    return hours < 48 ? `${hours} h` : `${Math.floor(hours / 24)} días`;
}
