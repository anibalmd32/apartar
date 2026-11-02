import { FigureItem, FigureList } from '@/components/ui/figure-list';
import { Meter } from '@/components/ui/meter';
import { SectionCard } from '@/components/ui/section-card';
import { StatCard } from '@/components/ui/stat-card';
import { BillingTrend } from '@/features/platform/components/BillingTrend';
import { MailSummaryCard } from '@/features/platform/mail/MailSummaryCard';
import PlatformLayout from '@/layouts/platform-layout';
import { pf } from '@/lib/surfaces';
import { cn } from '@/lib/utils';
import { type MailSummary } from '@/types/mail';
import { type PlatformMetrics } from '@/types/platform';
import { cilBan, cilBuilding, cilDescription, cilDollar } from '@coreui/icons';
import { Link } from '@inertiajs/react';

const DASH = '—';
const count = (value: number) => new Intl.NumberFormat('es-VE').format(value);

const PERIOD_LABELS: Record<number, string> = { 30: '30 días', 90: '90 días', 365: '12 meses' };

interface DashboardProps {
    metrics: PlatformMetrics;
    periods?: readonly number[];
    mail?: MailSummary | null;
}

export default function PlatformDashboard({ metrics, periods = [30], mail }: DashboardProps) {
    const { organizations, byPlan, mrr, invoices, network, gmv } = metrics;
    const days = metrics.periodDays ?? 30;
    const periodLabel = PERIOD_LABELS[days] ?? `${days} días`;
    const planTotal = byPlan === null ? 0 : Object.values(byPlan).reduce((sum, total) => sum + total, 0);

    return (
        <PlatformLayout
            title="Resumen de la plataforma"
            description="Cifras agregadas: ninguna identifica a un cliente final."
            actions={
                <nav aria-label="Período" className="bg-surface-2 inline-flex items-center gap-0.5 rounded-[10px] p-[3px]">
                    {periods.map((option) => (
                        <Link
                            key={option}
                            href={pf(`?periodo=${option}`)}
                            aria-current={option === days ? 'page' : undefined}
                            className={cn(
                                'touch:h-11 flex h-[34px] items-center rounded-lg px-3.5 text-[13px]',
                                option === days ? 'bg-base-100 shadow-card font-bold' : 'text-ink-3 hover:text-base-content font-medium',
                            )}
                        >
                            {PERIOD_LABELS[option] ?? `${option} días`}
                        </Link>
                    ))}
                </nav>
            }
        >
            <div className="stat-stack lg:grid lg:grid-cols-4 lg:gap-4">
                <section aria-label="MRR" className="flex">
                    <StatCard label="MRR" value={mrr?.usd ?? DASH} icon={cilDollar} tone="success" className="flex-1">
                        Suscripciones activas, en gracia y vencidas; sin suspendidas ni canceladas.
                    </StatCard>
                </section>

                <section aria-label="Organizaciones" className="flex">
                    <StatCard
                        label="Organizaciones activas"
                        value={organizations === null ? DASH : count(organizations.active)}
                        icon={cilBuilding}
                        href={pf('/organizaciones')}
                        className="flex-1"
                    >
                        {organizations === null ? null : (
                            <span>
                                de <strong>{organizations.total}</strong> · {organizations.newLast30Days} nuevas en {periodLabel}
                            </span>
                        )}
                    </StatCard>
                </section>

                <section aria-label="Suspendidas" className="flex">
                    <StatCard
                        label="Suspendidas"
                        value={organizations === null ? DASH : count(organizations.suspended)}
                        icon={cilBan}
                        tone="error"
                        href={pf('/organizaciones?estado=suspended')}
                        className="flex-1"
                    >
                        {metrics.suspendedLongOverdue ? `${metrics.suspendedLongOverdue} con factura vencida > 7 d` : null}
                    </StatCard>
                </section>

                <section aria-label="Facturas" className="flex">
                    <StatCard
                        label="Comprobantes por revisar"
                        value={invoices === null ? DASH : count(invoices.pendingReview)}
                        icon={cilDescription}
                        tone="warning"
                        href={pf('/facturas')}
                        className="flex-1"
                    >
                        {invoices === null
                            ? null
                            : invoices.pendingReviewOld
                              ? `${invoices.pendingReviewOld} con más de 48 h`
                              : `${invoices.overdue} facturas vencidas`}
                    </StatCard>
                </section>
            </div>

            <BillingTrend monthly={metrics.monthly ?? null} />

            {mail === undefined ? null : <MailSummaryCard summary={mail} />}

            <div className="grid grid-cols-1 gap-4">
                <SectionCard title="Organizaciones por plan" aria-label="Por plan">
                    {byPlan === null ? (
                        <p>{DASH}</p>
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {Object.entries(byPlan).map(([code, total]) => (
                                <Meter
                                    key={code}
                                    as="li"
                                    label={code}
                                    value={count(total)}
                                    percentage={planTotal === 0 ? 0 : (total / planTotal) * 100}
                                />
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>

            <SectionCard title="Red y volumen" aria-label="Red y volumen">
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-[3fr_2fr] lg:gap-3">
                    <section aria-label="Red" className="flex flex-col gap-2">
                        <p className="text-ink-3 text-xs">Cuentas multi-negocio: las que siguen catálogos de más de un negocio.</p>
                        {network === null ? (
                            <p>{DASH}</p>
                        ) : (
                            <FigureList className="lg:grid-cols-3">
                                <FigureItem label="Cuentas de clientes" value={count(network.accounts)} />
                                <FigureItem label="Seguimientos a catálogos" value={count(network.subscriptions)} />
                                <FigureItem label="Multi-negocio" value={count(network.multiBusinessAccounts)} />
                            </FigureList>
                        )}
                    </section>

                    <section aria-label={`Volumen en ${periodLabel}`} className="flex flex-col gap-2">
                        <p className="text-ink-3 text-xs">Volumen en {periodLabel}: lo que mueve la plataforma, no lo que ingresa.</p>
                        {gmv === null ? (
                            <p>{DASH}</p>
                        ) : (
                            <FigureList className="lg:grid-cols-2">
                                <FigureItem label="Comprometido (GMV)" value={gmv.committedLast30Days.usd} />
                                <FigureItem label="Cobrado" value={gmv.collectedLast30Days.usd} />
                            </FigureList>
                        )}
                    </section>
                </div>
            </SectionCard>
        </PlatformLayout>
    );
}
