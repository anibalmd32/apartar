import { useCallback, useEffect, useState } from 'react';

import { CONSENT_EVENT, CONSENT_REOPEN_EVENT, readConsent, writeConsent, type ConsentLevel } from '../lib/consent';

export function useConsent(): {
    level: ConsentLevel | null;
    asking: boolean;
    choose: (level: ConsentLevel) => void;
} {
    const [level, setLevel] = useState<ConsentLevel | null>(() => readConsent());
    const [reopened, setReopened] = useState(false);

    useEffect(() => {
        const follow = (event: Event): void => {
            const next: unknown = (event as CustomEvent<unknown>).detail;

            if (next === 'all' || next === 'essential') {
                setLevel(next);
            }
        };
        const reopen = (): void => setReopened(true);

        window.addEventListener(CONSENT_EVENT, follow);
        window.addEventListener(CONSENT_REOPEN_EVENT, reopen);

        return () => {
            window.removeEventListener(CONSENT_EVENT, follow);
            window.removeEventListener(CONSENT_REOPEN_EVENT, reopen);
        };
    }, []);

    const choose = useCallback((next: ConsentLevel) => {
        writeConsent(next);
        setLevel(next);
        setReopened(false);
    }, []);

    return { level, asking: level === null || reopened, choose };
}

export function reopenConsent(): void {
    window.dispatchEvent(new Event(CONSENT_REOPEN_EVENT));
}
