import { PER_PAGE_OPTIONS } from '@/features/data-table/hooks/useServerTable';
import { type TableMeta } from '@/types/data-table';

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
        <div className="flex flex-wrap items-center justify-between gap-3 text-[13px]">
            <label className="flex items-center gap-2">
                <span className="text-ink-3">Por página</span>
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
                <span className="text-ink-3">
                    {meta.total} {meta.total === 1 ? 'resultado' : 'resultados'}
                </span>
            ) : null}

            <nav className="flex items-center gap-2" aria-label="Paginación">
                <button type="button" className="btn btn-sm" disabled={!hasPrevious || loading} onClick={() => onPage(meta.page - 1)}>
                    Anterior
                </button>
                <span className="text-ink-2 px-1 font-medium">
                    {counted ? `Página ${meta.page} de ${Math.max(meta.lastPage ?? 1, 1)}` : `Página ${meta.page}`}
                </span>
                <button type="button" className="btn btn-sm" disabled={!meta.hasMore || loading} onClick={() => onPage(meta.page + 1)}>
                    Siguiente
                </button>
            </nav>
        </div>
    );
}
