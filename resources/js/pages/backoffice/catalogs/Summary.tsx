import { PageActions } from '@/components/ui/page-actions';
import { SectionCard } from '@/components/ui/section-card';
import { SummaryKpis } from '@/features/catalogs/components/summary/SummaryKpis';
import { SummaryPayments } from '@/features/catalogs/components/summary/SummaryPayments';
import { SummaryPeriodCharts } from '@/features/catalogs/components/summary/SummaryPeriodCharts';
import { SummaryPeriodFilter } from '@/features/catalogs/components/summary/SummaryPeriodFilter';
import { SummaryProducts } from '@/features/catalogs/components/summary/SummaryProducts';
import { SummarySafeguard } from '@/features/catalogs/components/summary/SummarySafeguard';
import { SummaryDebtors, SummaryStatuses } from '@/features/catalogs/components/summary/SummarySide';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { bo } from '@/lib/surfaces';
import { type CatalogSummaryProps } from '@/types/catalog-summary';
import { cilPencil } from '@coreui/icons';
import { Link } from '@inertiajs/react';

export default function CatalogSummary(props: CatalogSummaryProps) {
    const { catalog, totals, statuses, products, debtors, period, periodTotals, series, safeguard, payments, paymentsTruncated } = props;
    const withParallel = safeguard !== null;

    return (
        <BackofficeLayout
            title={catalog.name}
            description="Ventas, cobros e inventario de este catálogo. Incluye los apartados hechos desde el panel con sus productos."
            actions={
                <PageActions actions={[{ label: 'Editar catálogo', icon: cilPencil, href: catalog.editUrl, variant: 'secondary', primary: true }]} />
            }
        >
            <div className="flex flex-col gap-4">
                <Link href={bo('/catalogos')} className="hit-area text-ink-2 self-start text-sm hover:underline">
                    ← Catálogos
                </Link>
                <SummaryKpis totals={totals} />
                <SummaryPeriodFilter period={period} url={catalog.url} />
                <SummaryPeriodCharts period={period} totals={periodTotals} series={series} withParallel={withParallel} />
                {safeguard !== null ? (
                    <SectionCard
                        title="Resguardo en dólar paralelo"
                        subtitle="Lo que vale en dólar paralelo lo que cobraste en este periodo, y dónde lo tienes."
                    >
                        <SummarySafeguard safeguard={safeguard} />
                    </SectionCard>
                ) : null}
                <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <SectionCard title="Por producto">
                        <SummaryProducts products={products} />
                    </SectionCard>
                    <div className="flex flex-col gap-4">
                        <SectionCard title="Apartados por estado">
                            <SummaryStatuses statuses={statuses} />
                        </SectionCard>
                        <SectionCard title="Quiénes más deben">
                            <SummaryDebtors debtors={debtors} />
                        </SectionCard>
                    </div>
                </div>
                <SectionCard title="Pagos del periodo">
                    <SummaryPayments payments={payments} truncated={paymentsTruncated} withParallel={withParallel} />
                </SectionCard>
            </div>
        </BackofficeLayout>
    );
}
