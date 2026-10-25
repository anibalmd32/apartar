import { Alert } from '@/components/ui/alert';
import { AuditEntryRow } from '@/features/tenancy/components/AuditEntryRow';
import { AuditFilters } from '@/features/tenancy/components/AuditFilters';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type AuditActorOption, type AuditEntry, type AuditEventGroup, type AuditFilters as Filters } from '@/types/audit';
import { type Paginated } from '@/types/data-table';
import { router } from '@inertiajs/react';

interface AuditPageProps extends Paginated<AuditEntry> {
    events: AuditEventGroup[];
    actors: AuditActorOption[];
    timezone: string;
}

export default function AuditIndex({ rows: entries, meta, events, actors, timezone }: AuditPageProps) {
    const filters = meta.filters as unknown as Filters;

    const goToPage = (page: number) =>
        router.get('/backoffice/auditoria', { ...cleanFilters(filters), page }, { preserveState: false, preserveScroll: true });

    return (
        <BackofficeLayout
            title="Auditoría"
            description="Quién hizo qué y cuándo en tu negocio. Es un registro de solo lectura: no se edita ni se borra."
        >
            <AuditFilters filters={filters} events={events} actors={actors} />

            {meta.ignoredFilters.length > 0 ? <Alert tone="info">Algún filtro del enlace no se pudo aplicar y se muestra el resto.</Alert> : null}

            <section aria-label="Registro" className="flex flex-col gap-3">
                <p className="text-ink-3 text-xs">
                    {meta.total} {meta.total === 1 ? 'entrada' : 'entradas'}
                </p>

                {entries.length === 0 ? (
                    <p className="bg-base-100 border-line text-ink-2 rounded-2xl border border-dashed p-8 text-center text-sm">
                        No hay entradas que coincidan. Si acabas de configurar tu negocio, aquí irán apareciendo los cambios que hagáis.
                    </p>
                ) : (
                    byDay(entries, timezone).map(([day, items]) => (
                        <div key={day} className="flex flex-col gap-2">
                            <h2 className="text-eyebrow text-ink-3 mt-2">{day}</h2>
                            <ul className="bg-base-100 border-line shadow-card divide-line flex flex-col divide-y rounded-2xl border p-5">
                                {items.map((entry) => (
                                    <AuditEntryRow key={entry.id} entry={entry} timezone={timezone} area={areaOf(events, entry.event)} />
                                ))}
                            </ul>
                        </div>
                    ))
                )}

                {(meta.lastPage ?? 1) > 1 ? (
                    <nav className="flex items-center justify-center gap-2" aria-label="Paginación del registro">
                        <button type="button" className="btn btn-sm" disabled={meta.page <= 1} onClick={() => goToPage(meta.page - 1)}>
                            Anterior
                        </button>

                        <span className="text-ink-2 px-1 text-[13px] font-medium">
                            {meta.page} de {meta.lastPage}
                        </span>

                        <button type="button" className="btn btn-sm" disabled={!meta.hasMore} onClick={() => goToPage(meta.page + 1)}>
                            Siguiente
                        </button>
                    </nav>
                ) : null}
            </section>
        </BackofficeLayout>
    );
}

function areaOf(groups: readonly AuditEventGroup[], event: string | null): string | undefined {
    return event === null ? undefined : groups.find((group) => group.events.some((option) => option.value === event))?.label;
}

function byDay(entries: readonly AuditEntry[], timezone: string): [string, AuditEntry[]][] {
    const key = (date: Date) => new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(date);
    const today = key(new Date());
    const yesterday = key(new Date(Date.now() - 86_400_000));
    const groups = new Map<string, AuditEntry[]>();

    for (const entry of entries) {
        const day = entry.occurredAt === null ? 'Sin fecha' : key(new Date(entry.occurredAt));
        const label =
            day === today
                ? 'Hoy'
                : day === yesterday
                  ? 'Ayer'
                  : entry.occurredAt === null
                    ? day
                    : new Intl.DateTimeFormat('es-VE', { dateStyle: 'long', timeZone: timezone }).format(new Date(entry.occurredAt));
        groups.set(label, [...(groups.get(label) ?? []), entry]);
    }

    return [...groups.entries()];
}

function cleanFilters(filters: Filters): Record<string, string> {
    return Object.fromEntries(
        Object.entries(filters)
            .filter(([, value]) => value !== null && value !== '')
            .map(([key, value]) => [key, String(value)]),
    );
}
