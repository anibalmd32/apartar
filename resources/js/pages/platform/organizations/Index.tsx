import { Avatar } from '@/components/shell/avatar';
import { Icon } from '@/components/ui/icon';
import { OrganizationStatusBadge, PlanPill, SubscriptionStatusBadge } from '@/features/platform/components/labels';
import { OrganizationFilters } from '@/features/platform/components/OrganizationFilters';
import { OrganizationCard, RiskBadges } from '@/features/platform/components/OrganizationRowParts';
import PlatformLayout from '@/layouts/platform-layout';
import { type OrganizationRow } from '@/types/platform';
import { cilChevronLeft, cilChevronRight } from '@coreui/icons';
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
        router.get('/plataforma/organizaciones', { ...filters, pagina: 1, ...changes }, { preserveState: true, replace: true });
    const lastActivity = (row: OrganizationRow) => (row.lastActivityAt === null ? 'Nunca' : dateFormat.format(new Date(row.lastActivityAt)));
    const dateFormat = new Intl.DateTimeFormat('es', { day: 'numeric', month: 'short', year: 'numeric' });

    return (
        <PlatformLayout
            title="Organizaciones"
            description={`${total !== undefined ? `${total.toLocaleString('es-VE')} ${total === 1 ? 'negocio registrado' : 'negocios registrados'}. ` : ''}Plan, actividad y volumen de cada negocio. Nunca datos de sus clientes.`}
        >
            <OrganizationFilters filters={filters} plans={plans} onChange={visit} />

            {rows.length === 0 ? (
                <p className="border-line bg-base-100 text-ink-2 rounded-2xl border p-6 text-sm">Ninguna organización con estos filtros.</p>
            ) : (
                <div className="lg:border-line lg:bg-base-100 lg:overflow-x-auto lg:rounded-[14px] lg:border">
                    <ul className="flex flex-col gap-2.5 lg:hidden">
                        {rows.map((row) => (
                            <OrganizationCard key={row.id} row={row} lastActivity={lastActivity(row)} />
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
                                        <Link href={`/plataforma/organizaciones/${row.slug}`} className="group flex items-center gap-3">
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
                                            {row.subscriptionStatus !== null && row.subscriptionStatus !== 'active' ? (
                                                <SubscriptionStatusBadge status={row.subscriptionStatus} />
                                            ) : null}
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
                                    <td className="whitespace-nowrap">{lastActivity(row)}</td>
                                    <td>
                                        <RiskBadges row={row} dashWhenNone />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <nav aria-label="Páginas" className="lg:border-line flex items-center justify-between gap-2 py-3 lg:border-t lg:px-5">
                        <span className="text-ink-3 text-[13px]">Página {page}</span>
                        <span className="flex gap-2">
                            {page > 1 ? (
                                <button type="button" className="btn btn-sm" onClick={() => visit({ pagina: page - 1 })}>
                                    <Icon icon={cilChevronLeft} size={14} />
                                    Anterior
                                </button>
                            ) : null}
                            {hasMore ? (
                                <button type="button" className="btn btn-sm" onClick={() => visit({ pagina: page + 1 })}>
                                    Siguiente
                                    <Icon icon={cilChevronRight} size={14} />
                                </button>
                            ) : null}
                        </span>
                    </nav>
                </div>
            )}
        </PlatformLayout>
    );
}
