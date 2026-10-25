import { type DataTableColumn } from '@/types/data-table';
import { flexRender, type RowData } from '@tanstack/react-table';
import { type LegacyReactTable } from '@tanstack/react-table/legacy';
import { type ReactNode } from 'react';

interface TableBodyProps<TRow extends RowData> {
    table: LegacyReactTable<TRow>;
    columns: readonly DataTableColumn<TRow>[];
    loading: boolean;
    empty: ReactNode;
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
            <div className="bg-base-100 border-line text-ink-2 rounded-[14px] border border-dashed p-8 text-center text-sm" role="status">
                {empty}
            </div>
        );
    }

    const primary = columns.filter((column) => column.primary);
    const secondary = columns.filter((column) => !column.primary);

    return (
        <>
            <div className="table-frame hidden md:block">
                <table className="table">
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
            </div>

            <ul className="flex flex-col gap-2 md:hidden" aria-busy={loading || undefined}>
                {rows.map((row) => (
                    <li
                        key={row.id}
                        className={`bg-base-100 border-line shadow-card flex flex-col gap-2.5 rounded-2xl border p-4 ${loading ? 'opacity-50' : ''}`}
                    >
                        {primary.map((column) => (
                            <div key={column.id}>{column.cell(row.original)}</div>
                        ))}
                        {secondary.map((column) => (
                            <div key={column.id} className="flex flex-wrap items-center justify-between gap-2 text-[13px]">
                                <span className="text-ink-3">{column.header}</span>
                                <div>{column.cell(row.original)}</div>
                            </div>
                        ))}
                    </li>
                ))}
            </ul>
        </>
    );
}
