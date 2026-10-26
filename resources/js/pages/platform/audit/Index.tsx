import { DateRangeField } from '@/components/ui/date-range-field';
import { EmptyState } from '@/components/ui/empty-state';
import { SelectField } from '@/components/ui/select-field';
import { Pager } from '@/features/platform/components/Pager';
import PlatformLayout from '@/layouts/platform-layout';
import { PlatformSupportTabs } from '@/layouts/platform/nav';
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
    const time = new Intl.DateTimeFormat('es', { dateStyle: 'medium', timeStyle: 'short' });
    const visit = (changes: Partial<Record<keyof AuditFilters | 'pagina', string | number | null>>) =>
        router.get('/plataforma/auditoria', { ...filters, pagina: 1, ...changes }, { preserveState: true, replace: true });
    const filtered = Object.values(filters).some((value) => value !== null);

    return (
        <PlatformLayout title="Auditoría" description="Todo lo que hacen los operadores de Apartar. No se edita ni se borra.">
            <PlatformSupportTabs />

            <section aria-label="Filtros" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[220px_260px_minmax(0,1fr)]">
                <SelectField
                    label="Operador"
                    value={filters.operador === null ? '' : String(filters.operador)}
                    onChange={(event) => visit({ operador: event.target.value === '' ? null : Number(event.target.value) })}
                    options={[{ value: '', label: 'Todos' }, ...operators.map((operator) => ({ value: String(operator.id), label: operator.name }))]}
                />
                <SelectField
                    label="Acción"
                    value={filters.accion ?? ''}
                    onChange={(event) => visit({ accion: event.target.value === '' ? null : event.target.value })}
                    options={[{ value: '', label: 'Todas' }, ...actions]}
                />
                <DateRangeField
                    label="Fechas"
                    fromLabel="Desde"
                    from={filters.desde ?? ''}
                    to={filters.hasta ?? ''}
                    fromName="desde"
                    toName="hasta"
                    onFromChange={(event) => visit({ desde: event.target.value === '' ? null : event.target.value })}
                    onToChange={(event) => visit({ hasta: event.target.value === '' ? null : event.target.value })}
                />
            </section>

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
                                            href={`/plataforma/organizaciones/${entry.organization.slug}`}
                                            className="text-primary text-sm font-semibold hover:underline"
                                        >
                                            {entry.organization.name}
                                        </Link>
                                    ) : null}
                                    <time dateTime={entry.createdAt} className="text-ink-3 ml-auto text-xs whitespace-nowrap">
                                        {time.format(new Date(entry.createdAt))}
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
