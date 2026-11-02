import { EmptyState } from '@/components/ui/empty-state';
import { Pager } from '@/features/platform/components/Pager';
import { PlatformListFilters } from '@/features/platform/components/PlatformListFilters';
import PlatformLayout from '@/layouts/platform-layout';
import { PlatformSupportTabs } from '@/layouts/platform/nav';
import { compactQuery } from '@/lib/compact-query';
import { formatDateTime } from '@/lib/dates';
import { pf } from '@/lib/surfaces';
import { type PlatformAuditEntry } from '@/types/platform';
import { cilHistory } from '@coreui/icons';
import { Link, router } from '@inertiajs/react';

interface AuditFilters {
    operador: number | null;
    accion: string | null;
    desde: string | null;
    hasta: string | null;
}

interface AuditIndexProps {
    entries: readonly PlatformAuditEntry[];
    hasMore: boolean;
    page: number;
    filters: AuditFilters;
    operators: readonly { id: number; name: string }[];
    actions: readonly { value: string; label: string }[];
}

export default function AuditIndex({ entries, hasMore, page, filters, operators, actions }: AuditIndexProps) {
    const visit = (changes: Partial<Record<keyof AuditFilters | 'pagina', string | number | null>>) =>
        router.get(pf('/auditoria'), compactQuery({ ...filters, pagina: 1, ...changes }, { pagina: 1 }), { preserveState: true, replace: true });
    const filtered = Object.values(filters).some((value) => value !== null);

    return (
        <PlatformLayout title="Auditoría" description="Todo lo que hacen los operadores de Apartar. No se edita ni se borra.">
            <PlatformSupportTabs />

            <PlatformListFilters
                className="lg:grid lg:grid-cols-[220px_260px_minmax(0,1fr)] lg:items-stretch lg:gap-3"
                values={{ ...filters, operador: filters.operador === null ? null : String(filters.operador) }}
                onChange={(changes) =>
                    visit({ ...changes, ...('operador' in changes ? { operador: changes.operador === null ? null : Number(changes.operador) } : {}) })
                }
                selects={[
                    {
                        key: 'operador',
                        label: 'Operador',
                        all: 'Todos',
                        options: operators.map((operator) => ({ value: String(operator.id), label: operator.name })),
                    },
                    { key: 'accion', label: 'Acción', all: 'Todas', options: actions },
                ]}
            />

            <section aria-label="Registro" className="border-line bg-base-100 shadow-card overflow-hidden rounded-2xl border">
                {entries.length === 0 ? (
                    <div className="p-6">
                        <EmptyState icon={cilHistory} title={filtered ? 'Nada con estos filtros' : 'Todavía no hay actividad'}>
                            {filtered ? 'Prueba con otro operador, otra acción u otras fechas.' : 'Aquí aparecerá cada acción de los operadores.'}
                        </EmptyState>
                    </div>
                ) : (
                    <ol className="divide-line flex flex-col divide-y">
                        {entries.map((entry) => (
                            <li key={entry.id} className="flex flex-col gap-1 px-5 py-3.5">
                                <span className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                    <span className="text-sm font-semibold">{entry.operator ?? 'Sistema'}</span>
                                    <span className="text-ink-2 text-sm">{entry.label.toLowerCase()}</span>
                                    {entry.organization ? (
                                        <Link
                                            href={pf(`/organizaciones/${entry.organization.slug}`)}
                                            className="hit-area text-primary text-sm font-semibold hover:underline"
                                        >
                                            {entry.organization.name}
                                        </Link>
                                    ) : null}
                                    <time dateTime={entry.createdAt} className="text-ink-3 ml-auto text-xs whitespace-nowrap">
                                        {formatDateTime(entry.createdAt)}
                                    </time>
                                </span>
                                <p className="text-ink-2 text-[13px] break-words">{entry.description}</p>
                            </li>
                        ))}
                    </ol>
                )}
                <Pager page={page} hasMore={hasMore} onPage={(pagina) => visit({ pagina })} />
            </section>
        </PlatformLayout>
    );
}
