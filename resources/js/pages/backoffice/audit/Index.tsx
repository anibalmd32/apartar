import { AuditEntryRow } from '@/features/tenancy/components/AuditEntryRow';
import { AuditFilters } from '@/features/tenancy/components/AuditFilters';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type AuditActorOption, type AuditEntry, type AuditEventGroup, type AuditPageMeta, type AuditFilters as Filters } from '@/types/audit';
import { router } from '@inertiajs/react';

/**
 * Quién hizo qué y cuándo (`REQ-BOF-005`).
 *
 * En un producto cuya función es conciliar dinero ajeno, esta pantalla no es
 * una pestaña más: es donde un dueño encuentra la respuesta cuando un cliente
 * reclama un pago que dice haber hecho, o cuando hay que averiguar qué tocó
 * alguien que ya no trabaja en el negocio.
 *
 * **Solo lee.** No hay un botón que edite ni que borre una entrada, y no es que
 * estén escondidos: no existe la operación (RB-3). Una auditoría que la propia
 * aplicación puede corregir no responde a nada.
 *
 * La paginación es del servidor (RB-9) y los filtros viajan en la URL, así que
 * lo que se ve se puede compartir por enlace — que es justo lo que hace falta
 * cuando la respuesta hay que enseñársela a alguien.
 */

interface AuditPageProps {
    entries: AuditEntry[];
    meta: AuditPageMeta;
    filters: Filters;
    events: AuditEventGroup[];
    actors: AuditActorOption[];
    timezone: string;
}

export default function AuditIndex({ entries, meta, filters, events, actors, timezone }: AuditPageProps) {
    const goToPage = (page: number) =>
        router.get('/backoffice/auditoria', { ...cleanFilters(filters), page }, { preserveState: false, preserveScroll: true });

    return (
        <BackofficeLayout
            title="Auditoría"
            description="Quién hizo qué y cuándo en tu negocio. Es un registro de solo lectura: no se edita ni se borra."
        >
            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <h2 className="card-title text-base">Filtrar</h2>

                    <AuditFilters filters={filters} events={events} actors={actors} />
                </div>
            </section>

            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <header className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="card-title text-base">Registro</h2>
                        <p className="text-sm opacity-70">
                            {meta.total} {meta.total === 1 ? 'entrada' : 'entradas'}
                        </p>
                    </header>

                    {entries.length === 0 ? (
                        <p className="text-sm opacity-70">
                            No hay entradas que coincidan. Si acabas de configurar tu negocio, aquí irán apareciendo los cambios que hagáis.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th scope="col">Cuándo</th>
                                        <th scope="col">Qué pasó</th>
                                        <th scope="col">Quién</th>
                                        <th scope="col">Cambios</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {entries.map((entry) => (
                                        <AuditEntryRow key={entry.id} entry={entry} timezone={timezone} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {meta.lastPage > 1 ? (
                        <nav className="join self-center" aria-label="Paginación del registro">
                            <button type="button" className="join-item btn btn-sm" disabled={meta.page <= 1} onClick={() => goToPage(meta.page - 1)}>
                                Anterior
                            </button>

                            <span className="join-item btn btn-sm btn-disabled">
                                {meta.page} de {meta.lastPage}
                            </span>

                            <button
                                type="button"
                                className="join-item btn btn-sm"
                                disabled={meta.page >= meta.lastPage}
                                onClick={() => goToPage(meta.page + 1)}
                            >
                                Siguiente
                            </button>
                        </nav>
                    ) : null}
                </div>
            </section>
        </BackofficeLayout>
    );
}

/**
 * Los filtros vigentes como pares de `query string`, sin los vacíos.
 *
 * Cambiar de página tiene que **conservar el filtro**: una paginación que lo
 * pierde manda a la persona a la página 2 de otra cosa, que es de los errores
 * que más desconciertan porque el resultado parece plausible.
 */
function cleanFilters(filters: Filters): Record<string, string> {
    return Object.fromEntries(
        Object.entries(filters)
            .filter(([, value]) => value !== null && value !== '')
            .map(([key, value]) => [key, String(value)]),
    );
}
