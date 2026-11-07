import { MobileList, type MobileVariant } from '@/features/data-table/components/MobileList';
import { RowCheckbox } from '@/features/data-table/components/SelectionControls';
import { type RowSelection } from '@/features/data-table/hooks/useRowSelection';
import { type DataTableColumn } from '@/types/data-table';
import { flexRender, type RowData } from '@tanstack/react-table';
import { type LegacyReactTable } from '@tanstack/react-table/legacy';
import { Fragment, type ReactNode } from 'react';

interface TableBodyProps<TRow extends RowData> {
    table: LegacyReactTable<TRow>;
    columns: readonly DataTableColumn<TRow>[];
    loading: boolean;
    empty: ReactNode;
    header: ReactNode;
    selection?: RowSelection<TRow>;
    label: string;
    rowHref?: (row: TRow) => string | null | undefined;
    expanded?: (row: TRow) => ReactNode;
    mobileVariant?: MobileVariant;
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

export function TableBody<TRow extends RowData>({
    table,
    columns,
    loading,
    empty,
    header,
    selection,
    label,
    rowHref,
    expanded,
    mobileVariant,
}: TableBodyProps<TRow>) {
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
                                <Fragment key={row.id}>
                                    <tr className={selection?.isSelected(row.original) ? 'bg-primary/5' : undefined}>
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
                                    {expanded?.(row.original) ? (
                                        <tr className="bg-base-200/50">
                                            <td colSpan={columns.length + (selection ? 1 : 0)} className="p-0">
                                                {expanded(row.original)}
                                            </td>
                                        </tr>
                                    ) : null}
                                </Fragment>
                            ))}
                        </tbody>
                    )}
                </table>
            </div>

            <MobileList
                rows={rows}
                columns={columns}
                loading={loading}
                label={label}
                selection={selection}
                rowHref={rowHref}
                expanded={expanded}
                variant={mobileVariant}
            />
        </>
    );
}
