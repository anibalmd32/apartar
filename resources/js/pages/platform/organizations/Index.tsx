import { Avatar } from '@/components/shell/avatar';
import { EmptyState } from '@/components/ui/empty-state';
import { BillingBadge, OrganizationStatusBadge, PlanPill } from '@/features/platform/components/labels';
import { OrganizationFilters } from '@/features/platform/components/OrganizationFilters';
import { OrganizationCard, RiskBadges } from '@/features/platform/components/OrganizationRowParts';
import { Pager } from '@/features/platform/components/Pager';
import PlatformLayout from '@/layouts/platform-layout';
import { compactQuery } from '@/lib/compact-query';
import { formatDate } from '@/lib/dates';
import { pf } from '@/lib/surfaces';
import { type OrganizationRow } from '@/types/platform';
import { cilBuilding } from '@coreui/icons';
import { Link, router } from '@inertiajs/react';

interface DirectoryProps {
    rows: readonly OrganizationRow[];
    hasMore: boolean;
    page: number;
    filters: Record<string, string | boolean | null>;
    plans: Readonly<Record<string, string>>;
    total?: number;
}

export default function OrganizationsIndex({ rows, hasMore, page, filters, plans, total }: DirectoryProps) {
    const visit = (changes: Record<string, string | boolean | number | null>) =>
        router.get(pf('/organizaciones'), compactQuery({ ...filters, pagina: 1, ...changes }, { pagina: 1 }), { preserveState: true, replace: true });
    const lastActivity = (row: OrganizationRow) => (row.lastActivityAt === null ? null : formatDate(row.lastActivityAt));
    const filtered = Object.values(filters).some((value) => value !== null && value !== false && value !== '');

    return (
        <PlatformLayout
            title="Organizaciones"
            description={`${total !== undefined ? `${total.toLocaleString('es-VE')} ${total === 1 ? 'negocio registrado' : 'negocios registrados'}. ` : ''}Plan, actividad y volumen de cada negocio. Nunca datos de sus clientes.`}
        >
            <OrganizationFilters filters={filters} plans={plans} onChange={visit} />

            {rows.length === 0 ? (
                <div className="border-line bg-base-100 rounded-2xl border p-6">
                    <EmptyState
                        icon={cilBuilding}
                        title={filtered ? 'Ninguna organización con estos filtros' : 'Todavía no hay organizaciones'}
                        action={
                            filtered ? (
                                <Link href={pf('/organizaciones')} className="btn btn-sm">
                                    Quitar filtros
                                </Link>
                            ) : null
                        }
                    >
                        {filtered ? 'Prueba con otro plan, estado o búsqueda.' : 'Aquí aparecerá cada negocio que se registre.'}
                    </EmptyState>
                </div>
            ) : (
                <div className="lg:border-line lg:bg-base-100 lg:overflow-x-auto lg:rounded-[14px] lg:border">
                    <ul className="flex flex-col gap-2.5 lg:hidden">
                        {rows.map((row) => (
                            <OrganizationCard
                                key={row.id}
                                row={row}
                                planName={row.planCode === null ? null : (plans[row.planCode] ?? row.planCode)}
                                lastActivity={lastActivity(row)}
                            />
                        ))}
                    </ul>

                    <table className="table max-lg:hidden">
                        <thead>
                            <tr>
                                <th>Organización</th>
                                <th>Plan</th>
                                <th>Estado</th>
                                <th>Uso</th>
                                <th>Volumen</th>
                                <th>Última actividad</th>
                                <th>Riesgo</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.id}>
                                    <td>
                                        <Link href={pf(`/organizaciones/${row.slug}`)} className="group flex items-center gap-3">
                                            <Avatar name={row.name} size={34} />
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-semibold group-hover:underline">{row.name}</span>
                                                <span className="text-ink-3 block truncate text-xs">{row.slug}</span>
                                            </span>
                                        </Link>
                                    </td>
                                    <td>
                                        <PlanPill code={row.planCode} />
                                    </td>
                                    <td>
                                        <span className="flex flex-col items-start gap-1">
                                            <OrganizationStatusBadge status={row.status} />
                                            <BillingBadge row={row} />
                                        </span>
                                    </td>
                                    <td>
                                        <span className="block">
                                            {row.counts?.products ?? '—'} / {row.counts?.activeLayaways ?? '—'}
                                        </span>
                                        <span className="text-ink-3 text-xs">productos / apartados</span>
                                    </td>
                                    <td>
                                        <span className="block">{row.volume?.collectedLast30Days.usd ?? '—'}</span>
                                        <span className="text-ink-3 text-xs">cobrado 30 d</span>
                                    </td>
                                    <td className="whitespace-nowrap">{lastActivity(row) ?? 'Nunca'}</td>
                                    <td>
                                        <RiskBadges row={row} dashWhenNone />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <Pager page={page} hasMore={hasMore} onPage={(pagina) => visit({ pagina })} className="max-lg:border-t-0 max-lg:px-0" />
                </div>
            )}
        </PlatformLayout>
    );
}
