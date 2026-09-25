import { TableBody } from '@/features/data-table/components/TableBody';
import { TableHeader } from '@/features/data-table/components/TableHeader';
import { TablePagination } from '@/features/data-table/components/TablePagination';
import { TableToolbar } from '@/features/data-table/components/TableToolbar';
import { type ServerTable } from '@/features/data-table/hooks/useServerTable';
import { type RowData } from '@tanstack/react-table';
import { type ReactNode } from 'react';

/**
 * La tabla server-driven del backoffice (`REQ-BOF-045`).
 *
 * Compone cuatro piezas y no hace nada más (RB-9): `TableToolbar` con los
 * filtros de la pantalla y los avisos, `TableHeader` con el orden,
 * `TableBody` con las filas y sus estados, y `TablePagination`. Todo el estado
 * vive en `useServerTable`, que lo lee de la URL; aquí solo se pinta.
 *
 * La usan hoy el inventario y mañana cobranza, clientes, comprobantes y
 * suscriptores, y también el backoffice de plataforma (RB-10): allí la única
 * diferencia está en el servidor, que consulta sin el global scope.
 */

interface DataTableProps<TRow extends RowData> {
    table: ServerTable<TRow>;
    /** Qué se enseña sin filas: la definición del filtro aplicado, no un «sin datos» (RB-7). */
    empty: ReactNode;
    /** Los filtros de la pantalla. */
    toolbar?: ReactNode;
    /** Nombre legible de cada filtro, para el aviso de SP-4. */
    filterLabels?: Readonly<Record<string, string>>;
    /** Nombre accesible de la tabla. */
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
