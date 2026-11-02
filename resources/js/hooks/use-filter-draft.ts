import { useState } from 'react';

export interface FilterDraft<T extends object> {
    draft: T;
    set: <K extends keyof T>(key: K, value: T[K]) => void;
    reset: () => void;
    changes: () => Partial<T>;
}

export function useFilterDraft<T extends object>(applied: T): FilterDraft<T> {
    const [draft, setDraft] = useState<T>(applied);

    return {
        draft,
        set: (key, value) => setDraft((current) => ({ ...current, [key]: value })),
        reset: () => setDraft(applied),
        changes: () => {
            const keys = Object.keys(draft) as (keyof T)[];

            return Object.fromEntries(keys.filter((key) => draft[key] !== applied[key]).map((key) => [key, draft[key]])) as Partial<T>;
        },
    };
}
