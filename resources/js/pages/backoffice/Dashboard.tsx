import { Icon } from '@/components/ui/icon';
import { CARD_LABELS, DashboardStatCard } from '@/features/dashboard/components/DashboardStatCard';
import { DashboardWarnings } from '@/features/dashboard/components/DashboardWarnings';
import { PendingReceiptsCard } from '@/features/dashboard/components/PendingReceiptsCard';
import { PlanAndRate } from '@/features/dashboard/components/PlanAndRate';
import { HowItWorks, SetupChecklist } from '@/features/dashboard/components/SetupChecklist';
import { SortableGrid, SortableTile } from '@/features/dashboard/components/SortableGrid';
import { SuspensionCard } from '@/features/dashboard/components/SuspensionCard';
import { LimitBanner } from '@/features/plan/components/LimitBanner';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { cn } from '@/lib/utils';
import { type BackofficeSharedData } from '@/types/backoffice';
import { type DashboardLayoutView, type DashboardProps, type DashboardSection } from '@/types/dashboard';
import { type CurrencyPageProps } from '@/types/exchange';
import { cilCheckAlt, cilMove, cilPlus, cilViewColumn, cilViewStream } from '@coreui/icons';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const DEFAULT_LAYOUT: DashboardLayoutView = {
    mode: 'stacked',
    sections: ['stats', 'plan'],
    cards: ['pendingReceipts', 'collectedThisMonth', 'customersOverdue', 'longOverdue', 'completedNotDelivered', 'unreadMessages'],
};

const SECTION_LABELS: Record<DashboardSection, string> = { stats: 'las cifras del día', plan: 'el plan y la tasa' };

export default function Dashboard({ metrics, links, pendingReceipts, suspension = null, longOverdueDays = 15, layout: savedLayout }: DashboardProps) {
    const { teamMember, currency } = usePage<BackofficeSharedData & { currency?: CurrencyPageProps }>().props;
    const setup = metrics.setup;
    const starting = setup !== null && !(setup.paymentAccount && setup.product && setup.publishedCatalog);
    const timezone = currency?.timezone;
    const firstName = teamMember?.name.split(' ')[0] ?? '';
    const [layout, setLayout] = useState<DashboardLayoutView>(savedLayout ?? DEFAULT_LAYOUT);
    const [editing, setEditing] = useState(false);
    const split = layout.mode === 'split';
    const hasPendingReceipts = pendingReceipts !== undefined && pendingReceipts !== null && pendingReceipts.length > 0;

    const save = (next: DashboardLayoutView) => {
        setLayout(next);
        router.put(
            '/backoffice/panel/disposicion',
            { mode: next.mode, sections: [...next.sections], cards: [...next.cards] },
            { preserveScroll: true, preserveState: true, only: [] },
        );
    };

    const sections: Record<DashboardSection, React.ReactNode> = {
        stats: (
            <section aria-label="Cifras del día">
                <SortableGrid
                    ids={layout.cards}
                    labelOf={(id) => CARD_LABELS[id]}
                    onChange={(ids) => save({ ...layout, cards: ids })}
                    className={cn('grid grid-cols-2 gap-2.5 lg:gap-4', !split && 'lg:grid-cols-3')}
                >
                    {layout.cards.map((id) => (
                        <SortableTile key={id} id={id} label={CARD_LABELS[id]} editing={editing}>
                            <DashboardStatCard id={id} metrics={metrics} links={links} longOverdueDays={longOverdueDays} />
                        </SortableTile>
                    ))}
                </SortableGrid>
            </section>
        ),
        plan: <PlanAndRate metrics={metrics} />,
    };

    return (
        <BackofficeLayout
            title={`${greeting(timezone)}${firstName ? `, ${firstName}` : ''}`}
            description={`${today(timezone)} · esto es lo que necesita tu atención hoy.`}
            actions={
                <>
                    <button
                        type="button"
                        className="btn btn-ghost"
                        onClick={() => save({ ...layout, mode: split ? 'stacked' : 'split' })}
                        title={split ? 'Ver las cifras arriba y el plan debajo' : 'Ver las cifras a un lado y el plan al otro'}
                    >
                        <Icon icon={split ? cilViewStream : cilViewColumn} />
                        {split ? 'Apilado' : 'Dos columnas'}
                    </button>
                    <button
                        type="button"
                        className={cn('btn', editing ? 'btn-primary' : 'btn-ghost')}
                        aria-pressed={editing}
                        onClick={() => setEditing(!editing)}
                    >
                        <Icon icon={editing ? cilCheckAlt : cilMove} />
                        {editing ? 'Listo' : 'Organizar'}
                    </button>
                    {teamMember?.capabilities?.includes('products') ? (
                        <Link href="/backoffice/productos/crear" className="btn btn-primary">
                            <Icon icon={cilPlus} />
                            Nuevo producto
                        </Link>
                    ) : null}
                </>
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

            {editing ? (
                <p className="text-ink-3 text-[13px]" role="status">
                    Arrastra las secciones y las tarjetas por su asa para ordenarlas. El orden se guarda solo para ti.
                </p>
            ) : null}

            <SortableGrid
                ids={layout.sections}
                labelOf={(id) => SECTION_LABELS[id]}
                onChange={(ids) => save({ ...layout, sections: ids })}
                className={cn('grid grid-cols-1 gap-4 lg:gap-5', split && 'items-start lg:grid-cols-2')}
            >
                {layout.sections.map((id) => (
                    <SortableTile key={id} id={id} label={SECTION_LABELS[id]} editing={editing}>
                        {sections[id]}
                    </SortableTile>
                ))}
            </SortableGrid>

            {hasPendingReceipts || metrics.warnings.length > 0 ? (
                <div className={cn('grid grid-cols-1 items-start gap-4 lg:gap-5', hasPendingReceipts && 'lg:grid-cols-[minmax(0,1fr)_360px]')}>
                    {hasPendingReceipts ? <PendingReceiptsCard receipts={pendingReceipts} inboxUrl={links.receipts} /> : null}
                    <DashboardWarnings warnings={metrics.warnings} />
                </div>
            ) : null}
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
