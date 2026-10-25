import { type DataTableColumn } from '@/types/data-table';
import { flexRender, type RowData } from '@tanstack/react-table';
import { type LegacyReactTable } from '@tanstack/react-table/legacy';

interface TableHeaderProps<TRow extends RowData> {
    table: LegacyReactTable<TRow>;
    columns: readonly DataTableColumn<TRow>[];
    sort: string | null;
    onSort: (sortKey: string) => void;
}

function directionOf(sort: string | null, sortKey: string): 'ascending' | 'descending' | 'none' {
    if (sort === sortKey) {
        return 'ascending';
    }

    return sort === `-${sortKey}` ? 'descending' : 'none';
}

const ARROWS = { ascending: '↑', descending: '↓', none: '' } as const;

export function TableHeader<TRow extends RowData>({ table, columns, sort, onSort }: TableHeaderProps<TRow>) {
    return (
        <thead>
            {table.getHeaderGroups().map((group) => (
                <tr key={group.id}>
                    {group.headers.map((header) => {
                        const column = columns.find((candidate) => candidate.id === header.column.id);
                        const sortKey = column?.sortKey;
                        const direction = sortKey === undefined ? 'none' : directionOf(sort, sortKey);
                        const label = flexRender(header.column.columnDef.header, header.getContext());

                        return (
                            <th
                                key={header.id}
                                scope="col"
                                aria-sort={sortKey === undefined ? undefined : direction}
                                className={column?.align === 'end' ? 'text-right' : undefined}
                            >
                                {sortKey === undefined ? (
                                    label
                                ) : (
                                    <button
                                        type="button"
                                        className="hover:text-base-content inline-flex items-center gap-1"
                                        onClick={() => onSort(sortKey)}
                                    >
                                        {label}
                                        <span aria-hidden="true">{ARROWS[direction]}</span>
                                    </button>
                                )}
                            </th>
                        );
                    })}
                </tr>
            ))}
        </thead>
    );
}
