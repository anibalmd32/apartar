import { useEffect, useRef, useState } from 'react';

export const FILTER_DEBOUNCE_MS = 400;

export function useDebouncedFilter(
    initial: string | null,
    send: (value: string | null) => void,
): readonly [value: string, change: (value: string) => void] {
    const [value, setValue] = useState(initial ?? '');
    const sent = useRef(initial ?? '');
    const latest = useRef(send);

    useEffect(() => {
        latest.current = send;
    });

    useEffect(() => {
        if (value === sent.current) {
            return;
        }

        const timer = window.setTimeout(() => {
            sent.current = value;
            latest.current(value.trim() === '' ? null : value);
        }, FILTER_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [value]);

    return [value, setValue] as const;
}
