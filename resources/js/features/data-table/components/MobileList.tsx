import { CompactList, CompactRow } from '@/components/ui/compact-list';
import { MobileCard } from '@/features/data-table/components/MobileCard';
import { PageCheckbox, RowCheckbox } from '@/features/data-table/components/SelectionControls';
import { useLongPress } from '@/features/data-table/hooks/useLongPress';
import { type RowSelection } from '@/features/data-table/hooks/useRowSelection';
import { cn } from '@/lib/utils';
import { type DataTableColumn, type MobileSlot } from '@/types/data-table';
import { Fragment, type ReactNode, useState } from 'react';

export type MobileVariant = 'list' | 'card';

interface MobileListProps<TRow> {
    rows: readonly { id: string; original: TRow }[];
    columns: readonly DataTableColumn<TRow>[];
    loading: boolean;
    label: string;
    selection?: RowSelection<TRow>;
    rowHref?: (row: TRow) => string | null | undefined;
    expanded?: (row: TRow) => ReactNode;
    variant?: MobileVariant;
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

export function MobileList<TRow>({ rows, columns, loading, label, selection, rowHref, expanded, variant = 'list' }: MobileListProps<TRow>) {
    const [selecting, setSelecting] = useState(false);

    if (variant === 'card') {
        const amountLabel = columns.find(
            (column) => column.mobile === 'amount' || (typeof column.mobile === 'object' && column.mobile.amount),
        )?.header;

        return (
            <ul aria-label={label} aria-busy={loading || undefined} className={cn('flex flex-col gap-3 md:hidden', loading && 'opacity-50')}>
                {rows.map((row) => (
                    <MobileCard
                        key={row.id}
                        slots={slotsOf(columns, row.original)}
                        fallbackTitle={columns[0]?.cell(row.original)}
                        href={rowHref?.(row.original) ?? null}
                        amountLabel={amountLabel}
                        expanded={expanded?.(row.original)}
                    />
                ))}
            </ul>
        );
    }

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
                    <Fragment key={row.id}>
                        <MobileRow
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
                        {expanded?.(row.original) ? <li className="list-none px-1 pb-2">{expanded(row.original)}</li> : null}
                    </Fragment>
                ))}
            </CompactList>
        </div>
    );
}
