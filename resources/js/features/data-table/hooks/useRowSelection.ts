import { useCallback, useMemo, useState } from 'react';

export interface RowSelectionOptions<TRow> {
    rows: readonly TRow[];
    getId: (row: TRow) => number;
    canSelect?: (row: TRow) => boolean;
    labelOf: (row: TRow) => string;
    disabledHint?: string;
}

export interface RowSelection<TRow> {
    readonly ids: readonly number[];
    readonly count: number;
    readonly selectableCount: number;
    readonly allSelected: boolean;
    readonly someSelected: boolean;
    readonly disabledHint?: string;
    isSelected: (row: TRow) => boolean;
    canSelect: (row: TRow) => boolean;
    labelOf: (row: TRow) => string;
    toggle: (row: TRow) => void;
    toggleAll: () => void;
    clear: () => void;
}

const always = () => true;

/**
 * Selection limited to the rows on screen: ids that leave the page or stop being selectable drop out.
 */
export function useRowSelection<TRow>({ rows, getId, canSelect = always, labelOf, disabledHint }: RowSelectionOptions<TRow>): RowSelection<TRow> {
    const [picked, setPicked] = useState<ReadonlySet<number>>(() => new Set());

    const selectable = useMemo(() => rows.filter(canSelect).map(getId), [rows, canSelect, getId]);
    const ids = useMemo(() => selectable.filter((id) => picked.has(id)), [selectable, picked]);

    const allSelected = selectable.length > 0 && ids.length === selectable.length;

    const toggle = useCallback(
        (row: TRow) =>
            setPicked((current) => {
                const next = new Set(current);
                const id = getId(row);

                if (next.has(id)) {
                    next.delete(id);
                } else {
                    next.add(id);
                }

                return next;
            }),
        [getId],
    );

    return {
        ids,
        count: ids.length,
        selectableCount: selectable.length,
        allSelected,
        someSelected: ids.length > 0 && !allSelected,
        disabledHint,
        isSelected: (row) => ids.includes(getId(row)),
        canSelect,
        labelOf,
        toggle,
        toggleAll: () => setPicked(allSelected ? new Set() : new Set(selectable)),
        clear: () => setPicked(new Set()),
    };
}
