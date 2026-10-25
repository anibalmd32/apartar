import { Avatar } from '@/components/shell/avatar';
import { Badge } from '@/components/ui/badge';
import { BarChart } from '@/components/ui/bar-chart';
import { Meter } from '@/components/ui/meter';
import { SectionCard } from '@/components/ui/section-card';
import { StatCard } from '@/components/ui/stat-card';
import { UpgradePrompt } from '@/features/plan/components/UpgradePrompt';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { cn } from '@/lib/utils';
import { type SubscriberRow, type SubscribersPageProps } from '@/types/subscribers';
import { cilLibrary, cilPeople, cilUser, cilUserPlus } from '@coreui/icons';
import { Link } from '@inertiajs/react';

function versus(current: number, previous: number | null | undefined): string | null {
    if (previous === null || previous === undefined || previous === 0) {
        return null;
    }

    const change = Math.round(((current - previous) / previous) * 100);

    return `${change >= 0 ? '+' : ''}${change}% vs. semana pasada`;
}
export default function Subscribers({ subscribers, nextPageUrl, stats }: SubscribersPageProps) {
    const catalogs = stats.byCatalog?.length ?? null;
    const top = Math.max(1, ...(stats.byCatalog ?? []).map((row) => row.count));
    const byCatalog = stats.byCatalog !== null && stats.byCatalog.length > 0;

    return (
        <BackofficeLayout
            title="Suscriptores"
            description="Quién sigue tus catálogos para enterarse de lo nuevo. Sin datos de contacto: solo su nombre público."
        >
            <section aria-label="Cifras" className="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
                {stats.people !== null ? (
                    <StatCard label="Personas únicas" value={stats.people} icon={cilPeople}>
                        en todos tus catálogos
                    </StatCard>
                ) : null}
                <StatCard label="Suscripciones" value={stats.total} icon={cilLibrary} tone="info">
                    {catalogs !== null ? `en ${catalogs} ${catalogs === 1 ? 'catálogo' : 'catálogos'}` : null}
                </StatCard>
                {stats.last7Days !== null ? (
                    <StatCard label="Nuevos esta semana" value={`+${stats.last7Days}`} icon={cilUserPlus} tone="success">
                        {versus(stats.last7Days, stats.previous7Days) ?? 'en los últimos 7 días'}
                    </StatCard>
                ) : null}
                {stats.customers !== null && stats.customers !== undefined && stats.people !== null ? (
                    <StatCard label="Ya son clientes" value={stats.customers} icon={cilUser} tone="warning">
                        {stats.people > 0 ? `${Math.round((stats.customers / stats.people) * 100)}% de los suscriptores` : null}
                    </StatCard>
                ) : null}
                {stats.people === null ? (
                    <div className="border-line-strong text-ink-2 col-span-2 flex items-center rounded-2xl border border-dashed p-4 text-sm">
                        <UpgradePrompt feature="full_analytics" />
                    </div>
                ) : null}
            </section>

            {stats.weekly ? (
                <SectionCard title="Crecimiento semanal" subtitle="Suscripciones nuevas de cada semana, de las últimas 12.">
                    <BarChart
                        label="Suscripciones nuevas por semana"
                        data={stats.weekly.map((value, index, all) => ({
                            label: index === all.length - 1 ? 'Esta' : `S${index + 1}`,
                            value,
                        }))}
                    />
                </SectionCard>
            ) : null}

            <div className={cn('grid grid-cols-1 gap-4 lg:items-start lg:gap-6', byCatalog && 'lg:grid-cols-[minmax(0,1fr)_400px]')}>
                <SectionCard
                    title="Suscripciones recientes"
                    subtitle="No se pueden exportar: protegemos la privacidad de quienes te siguen."
                    className="lg:order-1"
                >
                    {subscribers.length === 0 ? (
                        <p className="text-ink-2 text-sm">
                            Todavía nadie sigue tus catálogos. Cuando compartas el enlace de uno, quien lo abra podrá seguirlo.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-1">
                            {subscribers.map((row, index) => (
                                <SubscriberItem key={`${row.displayName}:${row.catalogName}:${index}`} row={row} />
                            ))}
                        </ul>
                    )}

                    {nextPageUrl !== null ? (
                        <Link href={nextPageUrl} className="btn btn-sm mt-3 self-start">
                            Ver más
                        </Link>
                    ) : null}
                </SectionCard>

                {byCatalog ? (
                    <SectionCard title="Por catálogo" aria-label="Por catálogo" className="lg:order-2">
                        <ul className="flex flex-col gap-4">
                            {(stats.byCatalog ?? []).map((row) => (
                                <Meter key={row.catalogName} as="li" label={row.catalogName} value={row.count} percentage={(row.count / top) * 100} />
                            ))}
                        </ul>
                    </SectionCard>
                ) : null}
            </div>
        </BackofficeLayout>
    );
}

const DATE = new Intl.DateTimeFormat('es', { day: 'numeric', month: 'long', year: 'numeric' });

function SubscriberItem({ row }: { row: SubscriberRow }) {
    return (
        <li className="flex items-center gap-3 py-2 text-sm">
            {row.avatarUrl !== null ? (
                <img src={row.avatarUrl} alt="" className="size-9 shrink-0 rounded-full" referrerPolicy="no-referrer" />
            ) : (
                <Avatar name={row.displayName} size={36} />
            )}
            <div className="min-w-0 grow">
                <p className="truncate font-semibold">{row.displayName}</p>
                <p className="text-ink-3 truncate text-xs">
                    Sigue {row.catalogName} · desde el {DATE.format(new Date(row.subscribedAt))}
                </p>
            </div>
            {row.isCustomer && row.customerUrl !== null ? (
                <Link href={row.customerUrl} className="shrink-0" aria-label={`Ver la ficha de cliente de ${row.displayName}`}>
                    <Badge tone="success">Es cliente</Badge>
                </Link>
            ) : null}
        </li>
    );
}
