import { type ProductFormValues, type SpecificationFormRow } from '@/types/product';
import { useRef, useState } from 'react';

export const MAX_SPECIFICATIONS = 30;

export const MAX_SPECIFICATION_LENGTH = 200;

export function normalizeSpecification(text: string): string {
    return text.replace(/\s+/gu, ' ').trim();
}

function signature(text: string): string {
    return normalizeSpecification(text).toLowerCase();
}

function merged(current: readonly SpecificationFormRow[], items: readonly string[], nextKey: () => string): SpecificationFormRow[] {
    const seen = new Set(current.map((row) => signature(row.text)).filter((key) => key !== ''));
    const next = [...current];

    for (const item of items) {
        const text = normalizeSpecification(item).slice(0, MAX_SPECIFICATION_LENGTH);
        const key = signature(text);

        if (key === '' || seen.has(key)) {
            continue;
        }

        if (next.filter((row) => row.text.trim() !== '').length >= MAX_SPECIFICATIONS) {
            break;
        }

        seen.add(key);
        next.push({ key: nextKey(), text });
    }

    return next;
}

export function useSpecificationRows(product?: ProductFormValues | null) {
    const counter = useRef(0);
    const nextKey = () => {
        counter.current += 1;

        return `row-${counter.current}`;
    };

    const [rows, setRows] = useState<SpecificationFormRow[]>(() => (product?.specifications ?? []).map((text) => ({ key: nextKey(), text })));

    const add = (afterKey?: string): string => {
        const row = { key: nextKey(), text: '' };

        setRows((current) => {
            const index = afterKey === undefined ? -1 : current.findIndex((candidate) => candidate.key === afterKey);

            if (index === -1) {
                return [...current, row];
            }

            return [...current.slice(0, index + 1), row, ...current.slice(index + 1)];
        });

        return row.key;
    };

    const remove = (key: string) => {
        setRows((current) => current.filter((row) => row.key !== key));
    };

    const update = (key: string, text: string) => {
        setRows((current) => current.map((row) => (row.key === key ? { ...row, text } : row)));
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

    const replaceAll = (items: readonly string[]) => {
        setRows(merged([], items, nextKey));
    };

    const append = (items: readonly string[]) => {
        setRows((current) =>
            merged(
                current.filter((row) => row.text.trim() !== ''),
                items,
                nextKey,
            ),
        );
    };

    const payload = (): string[] => rows.map((row) => normalizeSpecification(row.text));

    const duplicateKeys = (): ReadonlySet<string> => {
        const seen = new Set<string>();
        const duplicates = new Set<string>();

        for (const row of rows) {
            const key = signature(row.text);

            if (key === '') {
                continue;
            }

            if (seen.has(key)) {
                duplicates.add(row.key);

                continue;
            }

            seen.add(key);
        }

        return duplicates;
    };

    const filledCount = rows.filter((row) => row.text.trim() !== '').length;

    return { rows, filledCount, add, remove, update, move, replaceAll, append, payload, duplicateKeys };
}

export type SpecificationRows = ReturnType<typeof useSpecificationRows>;
