import { SelectionBar } from '@/features/data-table/components/SelectionControls';
import { TableBody } from '@/features/data-table/components/TableBody';
import { TableHeader } from '@/features/data-table/components/TableHeader';
import { TablePagination } from '@/features/data-table/components/TablePagination';
import { TableToolbar } from '@/features/data-table/components/TableToolbar';
import { type RowSelection } from '@/features/data-table/hooks/useRowSelection';
import { type ServerTable } from '@/features/data-table/hooks/useServerTable';
import { type RowData } from '@tanstack/react-table';
import { type ReactNode } from 'react';

interface DataTableProps<TRow extends RowData> {
    table: ServerTable<TRow>;
    empty: ReactNode;
    toolbar?: ReactNode;
    filterLabels?: Readonly<Record<string, string>>;
    label: string;
    selection?: RowSelection<TRow>;
    selectionActions?: ReactNode;
}

export function DataTable<TRow extends RowData>({
    table,
    empty,
    toolbar,
    filterLabels = {},
    label,
    selection,
    selectionActions,
}: DataTableProps<TRow>) {
    const ignored = table.meta.ignoredFilters.map((key) => filterLabels[key] ?? key);
    const narrowed = Object.values(table.meta.filters).some(
        (value) => value !== null && value !== '' && !(Array.isArray(value) && value.length === 0),
    );
    const pristine = !narrowed && !table.loading && table.meta.page === 1 && table.table.getRowModel().rows.length === 0;

    return (
        <section className="flex flex-col gap-4" aria-label={label}>
            {pristine && !table.failed ? null : (
                <TableToolbar ignored={ignored} failed={table.failed} onRetry={table.retry}>
                    {toolbar}
                </TableToolbar>
            )}

            <TableBody
                table={table.table}
                columns={table.columns}
                loading={table.loading}
                empty={empty}
                selection={selection}
                header={
                    <TableHeader table={table.table} columns={table.columns} sort={table.meta.sort} onSort={table.toggleSort} selection={selection} />
                }
            />

            {selection ? <SelectionBar selection={selection} actions={selectionActions} /> : null}

            {pristine ? null : <TablePagination meta={table.meta} loading={table.loading} onPage={table.setPage} onPerPage={table.setPerPage} />}
        </section>
    );
}
