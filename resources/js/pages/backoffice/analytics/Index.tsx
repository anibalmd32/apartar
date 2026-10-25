import { SelectField } from '@/components/ui/select-field';
import { StatCard } from '@/components/ui/stat-card';
import { UpgradePrompt } from '@/features/plan/components/UpgradePrompt';
import { CatalogsCard, FunnelCard, MonthlyCard, SearchesCard, TopProductsCard } from '@/features/telemetry/components/AnalyticsSections';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { cn } from '@/lib/utils';
import { type AnalyticsPageProps } from '@/types/analytics';
import { cilCart, cilLibrary, cilPeople } from '@coreui/icons';
import { Link, router } from '@inertiajs/react';

const NUMBER = new Intl.NumberFormat('es-VE');

function versus(current: number, previous: number | undefined, fallback: string): string {
    if (previous === undefined || previous === 0) {
        return fallback;
    }

    const change = Math.round(((current - previous) / previous) * 100);

    return `${change >= 0 ? '+' : ''}${change}% vs. período anterior`;
}

export default function AnalyticsIndex({
    period,
    periods,
    full,
    analytics,
    catalog = null,
    catalogOptions = [],
    previous = null,
}: AnalyticsPageProps) {
    const { totals, funnel, catalogs, topProducts, searchesWithoutResults, monthly } = analytics;
    const context = `en los últimos ${period} días`;
    const query = (days: number, slug: string | null) => ({ periodo: days, ...(slug !== null ? { catalogo: slug } : {}) });

    const periodPicker = (
        <nav aria-label="Período" className="bg-surface-2 inline-flex items-center gap-0.5 rounded-[10px] p-[3px]">
            {periods.map((days) => (
                <Link
                    key={days}
                    href={route('analytics.index', query(days, catalog))}
                    className={cn(
                        'flex h-[34px] items-center rounded-lg px-3.5 text-[13px] transition-colors',
                        days === period ? 'bg-base-100 dark:bg-base-300 shadow-card font-bold' : 'text-ink-3 hover:text-base-content font-medium',
                    )}
                    aria-current={days === period ? 'page' : undefined}
                >
                    {days} días
                </Link>
            ))}
        </nav>
    );

    const catalogPicker =
        catalogOptions.length > 1 ? (
            <SelectField
                label="Catálogo"
                hideLabel
                inline
                value={catalog ?? ''}
                options={[{ value: '', label: 'Todos los catálogos' }, ...catalogOptions]}
                onChange={(event) => router.get(route('analytics.index', query(period, event.target.value === '' ? null : event.target.value)))}
            />
        ) : null;

    return (
        <BackofficeLayout
            title="Analítica"
            description="Cómo se usan tus catálogos. Solo totales: nunca quién hizo qué."
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    {periodPicker}
                    {catalogPicker}
                </div>
            }
        >
            <section aria-label="Cifras" className="grid grid-cols-2 gap-3 lg:grid-cols-3 lg:gap-4">
                <StatCard label="Visitas a catálogos" value={NUMBER.format(totals.visits)} icon={cilLibrary}>
                    {versus(totals.visits, previous?.visits, context)}
                </StatCard>
                <StatCard label="Visitantes" value={NUMBER.format(totals.visitors)} icon={cilPeople} tone="info">
                    {versus(totals.visitors, previous?.visitors, 'personas distintas')}
                </StatCard>
                <StatCard label="Apartados creados" value={NUMBER.format(totals.layaways)} icon={cilCart} tone="success">
                    {versus(totals.layaways, previous?.layaways, context)}
                </StatCard>
                {!full ? (
                    <div className="border-line-strong text-ink-2 col-span-2 flex items-center rounded-2xl border border-dashed p-4 text-sm lg:col-span-3">
                        <UpgradePrompt feature="full_analytics" />
                    </div>
                ) : null}
            </section>

            {funnel !== null || searchesWithoutResults !== null || topProducts !== null ? (
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:items-start lg:gap-6">
                    {funnel !== null ? <FunnelCard funnel={funnel} /> : null}
                    <div className="flex flex-col gap-4 lg:gap-6">
                        {searchesWithoutResults !== null ? <SearchesCard searches={searchesWithoutResults} /> : null}
                        {topProducts !== null ? <TopProductsCard products={topProducts} /> : null}
                    </div>
                </div>
            ) : null}

            {catalogs !== null || monthly !== null ? (
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:items-start lg:gap-6">
                    {catalogs !== null ? <CatalogsCard catalogs={catalogs} /> : null}
                    {monthly !== null ? <MonthlyCard monthly={monthly} /> : null}
                </div>
            ) : null}
        </BackofficeLayout>
    );
}
