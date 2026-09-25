import { PER_PAGE_OPTIONS } from '@/features/data-table/hooks/useServerTable';
import { type TableMeta } from '@/types/data-table';

/**
 * La paginación de una tabla server-driven (`REQ-BOF-045` RB-5, RB-6).
 *
 * Sirve a los dos modos de RB-6 sin preguntar cuál es: con `total` enseña
 * «página 2 de 7» y el total; en la paginación simple —la de las tablas que
 * crecen sin techo, donde el servidor no cuenta— enseña solo la página y si
 * hay siguiente, porque un «de 0» sería una cifra inventada.
 *
 * El selector ofrece exactamente los tamaños que el servidor acepta
 * (`PER_PAGE_OPTIONS`); uno que no esté en la lista caería a 25 al llegar allí.
 */

interface TablePaginationProps {
    meta: TableMeta;
    loading: boolean;
    onPage: (page: number) => void;
    onPerPage: (perPage: number) => void;
}

export function TablePagination({ meta, loading, onPage, onPerPage }: TablePaginationProps) {
    const counted = meta.total !== null && meta.lastPage !== null;
    const hasPrevious = meta.page > 1;

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
            <label className="flex items-center gap-2">
                <span className="opacity-70">Por página</span>
                <select
                    className="select select-sm select-bordered w-auto"
                    value={meta.perPage}
                    disabled={loading}
                    onChange={(event) => onPerPage(Number(event.target.value))}
                >
                    {PER_PAGE_OPTIONS.map((option) => (
                        <option key={option} value={option}>
                            {option}
                        </option>
                    ))}
                </select>
            </label>

            {counted ? (
                <span className="opacity-70">
                    {meta.total} {meta.total === 1 ? 'resultado' : 'resultados'}
                </span>
            ) : null}

            <nav className="join" aria-label="Paginación">
                <button type="button" className="join-item btn btn-sm" disabled={!hasPrevious || loading} onClick={() => onPage(meta.page - 1)}>
                    Anterior
                </button>
                <span className="join-item btn btn-sm btn-disabled">
                    {counted ? `Página ${meta.page} de ${Math.max(meta.lastPage ?? 1, 1)}` : `Página ${meta.page}`}
                </span>
                <button type="button" className="join-item btn btn-sm" disabled={!meta.hasMore || loading} onClick={() => onPage(meta.page + 1)}>
                    Siguiente
                </button>
            </nav>
        </div>
    );
}
