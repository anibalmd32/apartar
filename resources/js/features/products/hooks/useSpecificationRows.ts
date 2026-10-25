import { type ProductFormValues, type SpecificationFormRow } from '@/types/product';
import { useRef, useState } from 'react';

export interface SpecificationPayloadRow {
    group: string;
    label: string;
    value: string;
}

function initialRows(nextKey: () => string, product?: ProductFormValues | null): SpecificationFormRow[] {
    return (product?.specifications ?? []).map((specification) => ({
        key: nextKey(),
        id: specification.id,
        group: specification.group ?? '',
        label: specification.label,
        value: specification.value,
    }));
}

export function useSpecificationRows(product?: ProductFormValues | null) {
    const counter = useRef(0);
    const nextKey = () => {
        counter.current += 1;

        return `row-${counter.current}`;
    };

    const [rows, setRows] = useState<SpecificationFormRow[]>(() => initialRows(nextKey, product));

    const add = () => {
        setRows((current) => [...current, { key: nextKey(), id: null, group: '', label: '', value: '' }]);
    };

    const remove = (key: string) => {
        setRows((current) => current.filter((row) => row.key !== key));
    };

    const update = (key: string, field: 'group' | 'label' | 'value', value: string) => {
        setRows((current) => current.map((row) => (row.key === key ? { ...row, [field]: value } : row)));
    };

    const move = (from: number, to: number) => {
        setRows((current) => {
            const next = [...current];
            const [moved] = next.splice(from, 1);

            if (moved === undefined) {
                return current;
            }

            next.splice(to, 0, moved);

            return next;
        });
    };

    const payload = (): SpecificationPayloadRow[] =>
        rows.map((row) => ({ group: row.group.trim(), label: row.label.trim(), value: row.value.trim() }));

    const duplicateKeys = (): ReadonlySet<string> => {
        const seen = new Map<string, string>();
        const duplicates = new Set<string>();

        for (const row of rows) {
            const label = row.label.trim().toLowerCase();

            if (label === '') {
                continue;
            }

            const signature = `${row.group.trim().toLowerCase()}::${label}`;
            const first = seen.get(signature);

            if (first === undefined) {
                seen.set(signature, row.key);

                continue;
            }

            duplicates.add(first);
            duplicates.add(row.key);
        }

        return duplicates;
    };

    return { rows, add, remove, update, move, payload, duplicateKeys };
}
