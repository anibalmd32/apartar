import { PageCheckbox, RowCheckbox } from '@/features/data-table/components/SelectionControls';
import { type RowSelection } from '@/features/data-table/hooks/useRowSelection';
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
    selection?: RowSelection<TRow>;
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

function Card<TRow>({ row, columns, selection }: { row: TRow; columns: readonly DataTableColumn<TRow>[]; selection?: RowSelection<TRow> }) {
    const primary = columns.filter((column) => column.primary);
    const secondary = columns.filter((column) => !column.primary);

    const fields = (
        <>
            {primary.map((column) => (
                <div key={column.id}>{column.cell(row)}</div>
            ))}
            {secondary.map((column) => (
                <div key={column.id} className="flex flex-wrap items-center justify-between gap-2 text-[13px]">
                    <span className="text-ink-3">{column.header}</span>
                    <div>{column.cell(row)}</div>
                </div>
            ))}
        </>
    );

    if (selection === undefined) {
        return fields;
    }

    return (
        <div className="flex items-start gap-3">
            <div className="pt-3">
                <RowCheckbox selection={selection} row={row} />
            </div>
            <div className="flex min-w-0 flex-1 flex-col gap-2.5">{fields}</div>
        </div>
    );
}

export function TableBody<TRow extends RowData>({ table, columns, loading, empty, header, selection }: TableBodyProps<TRow>) {
    const rows = table.getRowModel().rows;

    if (rows.length === 0 && !loading) {
        return (
            <div className="bg-base-100 border-line text-ink-2 rounded-[14px] border border-dashed p-8 text-center text-sm" role="status">
                {empty}
            </div>
        );
    }

    return (
        <>
            <div className="table-frame hidden md:block">
                <table className="table">
                    {header}
                    {loading ? (
                        <Skeleton rows={Math.max(rows.length, 3)} columns={columns.length + (selection ? 1 : 0)} />
                    ) : (
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.id} className={selection?.isSelected(row.original) ? 'bg-primary/5' : undefined}>
                                    {selection ? (
                                        <td className="w-10">
                                            <RowCheckbox selection={selection} row={row.original} />
                                        </td>
                                    ) : null}
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

            {selection && selection.selectableCount > 0 ? (
                <div className="px-1 md:hidden">
                    <PageCheckbox selection={selection}>
                        <span className="text-ink-2 text-sm">Seleccionar todos los de esta página</span>
                    </PageCheckbox>
                </div>
            ) : null}

            <ul className="flex flex-col gap-2 md:hidden" aria-busy={loading || undefined}>
                {rows.map((row) => (
                    <li
                        key={row.id}
                        className={`bg-base-100 border-line shadow-card flex flex-col gap-2.5 rounded-2xl border p-4 ${loading ? 'opacity-50' : ''} ${selection?.isSelected(row.original) ? 'border-primary' : ''}`}
                    >
                        <Card row={row.original} columns={columns} selection={selection} />
                    </li>
                ))}
            </ul>
        </>
    );
}
