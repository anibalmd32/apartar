import { postForm } from '@/lib/json';
import { type Currency } from '@/types/money';
import { useRef, useState } from 'react';

export interface ReceiptReading {
    readonly amount: string | null;
    readonly currency: Currency | null;
    readonly paidAt: string | null;
    readonly reference: string | null;
    readonly bank: string | null;
}

export type ReadingState = 'idle' | 'reading' | 'read';

const READABLE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

export function isReadable(file: File | null): file is File {
    return file !== null && READABLE_TYPES.includes(file.type);
}

export function useReceiptReading(readUrl: string, onRead: (reading: ReceiptReading) => void) {
    const [state, setState] = useState<ReadingState>('idle');
    const latest = useRef(0);

    const read = (file: File | null) => {
        const ticket = ++latest.current;

        if (!isReadable(file)) {
            setState('idle');

            return;
        }

        const body = new FormData();
        body.append('file', file);
        setState('reading');

        postForm<{ reading: ReceiptReading }>(readUrl, body)
            .then(({ reading }) => {
                if (ticket === latest.current) {
                    onRead(reading);
                }
            })
            .catch(() => undefined)
            .finally(() => {
                if (ticket === latest.current) {
                    setState('read');
                }
            });
    };

    return { state, read };
}
