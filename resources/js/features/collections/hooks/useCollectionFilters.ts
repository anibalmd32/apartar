import { useDebouncedFilter } from '@/features/data-table/hooks/useDebouncedFilter';
import { useServerTable } from '@/features/data-table/hooks/useServerTable';
import { type CollectionFiltersState } from '@/types/collections';
import { type DataTableColumn, type TableMeta } from '@/types/data-table';
import { type RowData } from '@tanstack/react-table';

export const COLLECTION_PROPS = ['rows', 'meta', 'kind', 'definition', 'summary', 'awaitingDownPayment'] as const;

export function useCollectionFilters<TRow extends RowData>({
    rows,
    meta,
    columns,
}: {
    rows: readonly TRow[];
    meta: TableMeta;
    columns: readonly DataTableColumn<TRow>[];
}) {
    const table = useServerTable({ rows, meta, columns, only: COLLECTION_PROPS });
    const filters = meta.filters as unknown as CollectionFiltersState;
    const { setFilter } = table;
    const [search, setSearch] = useDebouncedFilter(filters.q, (value) => setFilter('q', value));

    const change = (key: keyof CollectionFiltersState, value: string | null) => {
        if (key === 'q') {
            setSearch(value ?? '');

            return;
        }

        setFilter(key, value);
    };

    return { table, filters: { ...filters, q: search === '' ? null : search }, change };
}
