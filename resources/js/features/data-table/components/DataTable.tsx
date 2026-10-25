import { TableBody } from '@/features/data-table/components/TableBody';
import { TableHeader } from '@/features/data-table/components/TableHeader';
import { TablePagination } from '@/features/data-table/components/TablePagination';
import { TableToolbar } from '@/features/data-table/components/TableToolbar';
import { type ServerTable } from '@/features/data-table/hooks/useServerTable';
import { type RowData } from '@tanstack/react-table';
import { type ReactNode } from 'react';

interface DataTableProps<TRow extends RowData> {
    table: ServerTable<TRow>;
    empty: ReactNode;
    toolbar?: ReactNode;
    filterLabels?: Readonly<Record<string, string>>;
    label: string;
}

export function DataTable<TRow extends RowData>({ table, empty, toolbar, filterLabels = {}, label }: DataTableProps<TRow>) {
    const ignored = table.meta.ignoredFilters.map((key) => filterLabels[key] ?? key);

    return (
        <section className="flex flex-col gap-4" aria-label={label}>
            <TableToolbar ignored={ignored} failed={table.failed} onRetry={table.retry}>
                {toolbar}
            </TableToolbar>

            <TableBody
                table={table.table}
                columns={table.columns}
                loading={table.loading}
                empty={empty}
                header={<TableHeader table={table.table} columns={table.columns} sort={table.meta.sort} onSort={table.toggleSort} />}
            />

            <TablePagination meta={table.meta} loading={table.loading} onPage={table.setPage} onPerPage={table.setPerPage} />
        </section>
    );
}
