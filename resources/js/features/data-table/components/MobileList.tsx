import { CompactList, CompactRow } from '@/components/ui/compact-list';
import { PageCheckbox, RowCheckbox } from '@/features/data-table/components/SelectionControls';
import { useLongPress } from '@/features/data-table/hooks/useLongPress';
import { type RowSelection } from '@/features/data-table/hooks/useRowSelection';
import { cn } from '@/lib/utils';
import { type DataTableColumn, type MobileSlot } from '@/types/data-table';
import { Fragment, type ReactNode, useState } from 'react';

interface MobileListProps<TRow> {
    rows: readonly { id: string; original: TRow }[];
    columns: readonly DataTableColumn<TRow>[];
    loading: boolean;
    label: string;
    selection?: RowSelection<TRow>;
    rowHref?: (row: TRow) => string | null | undefined;
}

function blank(node: ReactNode): boolean {
    return node === null || node === undefined || node === false || node === '';
}

export function slotsOf<TRow>(columns: readonly DataTableColumn<TRow>[], row: TRow): Record<MobileSlot, ReactNode[]> {
    const slots: Record<MobileSlot, ReactNode[]> = { media: [], title: [], subtitle: [], amount: [], status: [], meta: [], action: [] };

    for (const column of columns) {
        const mobile = column.mobile ?? 'meta';

        if (mobile === 'hidden') {
            continue;
        }

        const entries = typeof mobile === 'string' ? [[mobile, column.cell] as const] : Object.entries(mobile);

        for (const [slot, render] of entries) {
            const node = render?.(row);

            if (!blank(node)) {
                slots[slot as MobileSlot].push(<span key={`${column.id}:${slot}`}>{node}</span>);
            }
        }
    }

    return slots;
}

function joined(nodes: ReactNode[]): ReactNode {
    return nodes.map((node, index) => (
        <Fragment key={index}>
            {index > 0 ? ' · ' : null}
            {node}
        </Fragment>
    ));
}

function MobileRow<TRow>({
    row,
    columns,
    selection,
    selecting,
    href,
    onHold,
}: {
    row: TRow;
    columns: readonly DataTableColumn<TRow>[];
    selection?: RowSelection<TRow>;
    selecting: boolean;
    href: string | null;
    onHold: () => void;
}) {
    const selectable = selection?.canSelect(row) ?? false;
    const press = useLongPress(onHold, selectable && !selecting);
    const slots = slotsOf(columns, row);
    const toggle = selecting && selectable && selection ? () => selection.toggle(row) : undefined;

    return (
        <CompactRow
            {...press}
            title={slots.title.length > 0 ? slots.title : columns[0]?.cell(row)}
            subtitle={slots.subtitle.length > 0 ? joined(slots.subtitle) : null}
            media={slots.media.length > 0 ? slots.media : null}
            amount={slots.amount}
            status={slots.status}
            meta={slots.meta}
            action={selecting ? null : slots.action}
            href={selecting ? null : href}
            onActivate={toggle}
            leading={selecting && selection ? <RowCheckbox selection={selection} row={row} /> : null}
            selected={selection?.isSelected(row) ?? false}
            className={cn(selecting && !selectable && 'opacity-50')}
        />
    );
}

export function MobileList<TRow>({ rows, columns, loading, label, selection, rowHref }: MobileListProps<TRow>) {
    const [selecting, setSelecting] = useState(false);
    const canSelect = selection !== undefined && selection.selectableCount > 0;
    const active = selecting && canSelect;

    const stop = () => {
        setSelecting(false);
        selection?.clear();
    };

    return (
        <div className="flex flex-col gap-2 md:hidden">
            {canSelect ? (
                <div className="flex min-h-11 items-center justify-between gap-3 px-1">
                    {active ? (
                        <PageCheckbox selection={selection}>
                            <span className="text-ink-2 text-sm">Todos los de esta página</span>
                        </PageCheckbox>
                    ) : (
                        <span className="text-ink-3 text-[13px]">Mantén pulsado para elegir varios</span>
                    )}
                    <button
                        type="button"
                        className="btn btn-ghost h-11 min-h-11 px-3"
                        aria-pressed={active}
                        onClick={active ? stop : () => setSelecting(true)}
                    >
                        {active ? 'Cancelar' : 'Seleccionar'}
                    </button>
                </div>
            ) : null}
            <CompactList aria-label={label} aria-busy={loading || undefined} className={cn(loading && 'opacity-50')}>
                {rows.map((row) => (
                    <MobileRow
                        key={row.id}
                        row={row.original}
                        columns={columns}
                        selection={selection}
                        selecting={active}
                        href={rowHref?.(row.original) ?? null}
                        onHold={() => {
                            setSelecting(true);
                            selection?.toggle(row.original);
                        }}
                    />
                ))}
            </CompactList>
        </div>
    );
}
