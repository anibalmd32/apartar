import { type DataTableColumn } from '@/types/data-table';
import { flexRender, type RowData } from '@tanstack/react-table';
import { type LegacyReactTable } from '@tanstack/react-table/legacy';
import { type ReactNode } from 'react';

/**
 * Las filas de una tabla server-driven, en sus tres estados (`REQ-BOF-045`
 * RB-7, RB-8).
 *
 *  - **Cargando**: un *skeleton* con el mismo número de filas que había, para
 *    que la página no salte mientras llega el lote siguiente.
 *  - **Vacío**: con la definición del filtro aplicado, que la pone la pantalla
 *    —«ningún producto en borrador en Herramientas»— y no un «sin datos» que
 *    no dice si el filtro está mal o el negocio no tiene nada.
 *  - **Con filas**: una tabla en escritorio y **tarjetas apiladas en el
 *    móvil**, con las columnas prioritarias; el `body` nunca scrollea en
 *    horizontal.
 */

interface TableBodyProps<TRow extends RowData> {
    table: LegacyReactTable<TRow>;
    columns: readonly DataTableColumn<TRow>[];
    loading: boolean;
    empty: ReactNode;
    /** El `<thead>` de escritorio, que compone `DataTable` porque es quien sabe ordenar. */
    header: ReactNode;
}

function Skeleton({ rows, columns }: { rows: number; columns: number }) {
    return (
        <tbody aria-busy="true">
            {Array.from({ length: rows }, (_, row) => (
                <tr key={row}>
                    {Array.from({ length: columns }, (__, column) => (
                        <td key={column}>
                            <div className="skeleton h-4 w-full" />
                        </td>
                    ))}
                </tr>
            ))}
        </tbody>
    );
}

export function TableBody<TRow extends RowData>({ table, columns, loading, empty, header }: TableBodyProps<TRow>) {
    const rows = table.getRowModel().rows;

    if (rows.length === 0 && !loading) {
        return (
            <div className="bg-base-200 rounded-box p-6 text-center text-sm" role="status">
                {empty}
            </div>
        );
    }

    const primary = columns.filter((column) => column.primary);
    const secondary = columns.filter((column) => !column.primary);

    return (
        <>
            <table className="hidden table md:table">
                {header}
                {loading ? (
                    <Skeleton rows={Math.max(rows.length, 3)} columns={columns.length} />
                ) : (
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.id}>
                                {row.getVisibleCells().map((cell) => {
                                    const column = columns.find((candidate) => candidate.id === cell.column.id);

                                    return (
                                        <td key={cell.id} className={column?.align === 'end' ? 'text-right' : undefined}>
                                            {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                )}
            </table>

            <ul className="flex flex-col gap-2 md:hidden" aria-busy={loading || undefined}>
                {rows.map((row) => (
                    <li key={row.id} className={`bg-base-200 rounded-box flex flex-col gap-2 p-3 ${loading ? 'opacity-50' : ''}`}>
                        {primary.map((column) => (
                            <div key={column.id}>{column.cell(row.original)}</div>
                        ))}
                        {secondary.map((column) => (
                            <div key={column.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span className="opacity-70">{column.header}</span>
                                <div>{column.cell(row.original)}</div>
                            </div>
                        ))}
                    </li>
                ))}
            </ul>
        </>
    );
}
