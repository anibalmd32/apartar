import { type ChangeEvent, useState } from 'react';

export interface NumberDraftOptions {
    value: number | null;
    onChange: (value: number | null) => void;
    min?: number;
    max?: number;
}

export function digitsOf(raw: string): string {
    return raw.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
}

export function clampNumber(value: number, min?: number, max?: number): number {
    const floor = min ?? Number.NEGATIVE_INFINITY;
    const ceiling = max ?? Number.POSITIVE_INFINITY;

    return Math.min(Math.max(value, floor), ceiling);
}

const format = (value: number | null): string => (value === null || !Number.isFinite(value) ? '' : String(value));

export function useNumberDraft({ value, onChange, min, max }: NumberDraftOptions) {
    const [draft, setDraft] = useState(() => format(value));
    const [synced, setSynced] = useState(value);

    if (value !== synced) {
        setSynced(value);
        setDraft(format(value));
    }

    const change = (event: ChangeEvent<HTMLInputElement>) => {
        const digits = digitsOf(event.target.value);

        setDraft(digits);
        onChange(digits === '' ? null : Number(digits));
    };

    const commit = () => {
        if (draft === '') {
            setDraft(format(value));

            return;
        }

        const next = clampNumber(Number(draft), min, max);

        setDraft(String(next));

        if (next !== value) {
            onChange(next);
        }
    };

    return { value: draft, onChange: change, onBlur: commit, inputMode: 'numeric' as const, pattern: '[0-9]*' };
}
