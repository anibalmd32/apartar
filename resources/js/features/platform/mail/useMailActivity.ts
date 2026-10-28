import { echo } from '@/lib/echo';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { MAIL_ACTIVITY_CHANNEL, MAIL_ACTIVITY_EVENT, type MailActivityEvent, type MailActivityPage, propsToReload } from './mailActivity';

export const MAIL_ACTIVITY_DEBOUNCE_MS = 1_000;

export const MAIL_FALLBACK_POLL_MS = 60_000;

function useBatchedReload(page: MailActivityPage) {
    const latest = useRef(page);
    const pending = useRef(new Set<string>());
    const timer = useRef<number | null>(null);

    useEffect(() => {
        latest.current = page;
    });

    useEffect(
        () => () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        },
        [],
    );

    return useCallback((event: MailActivityEvent | null) => {
        for (const prop of propsToReload(latest.current, event)) {
            pending.current.add(prop);
        }

        if (pending.current.size === 0 || timer.current !== null) {
            return;
        }

        timer.current = window.setTimeout(() => {
            const only = [...pending.current];
            pending.current.clear();
            timer.current = null;
            router.reload({ only });
        }, MAIL_ACTIVITY_DEBOUNCE_MS);
    }, []);
}

export function useMailActivity(): boolean {
    const { component, props } = usePage();
    const schedule = useBatchedReload({ component, props: props as Record<string, unknown> });
    const [connected, setConnected] = useState(false);

    useEffect(() => {
        const socket = echo();

        if (socket === null) {
            return;
        }

        socket.private(MAIL_ACTIVITY_CHANNEL).listen(MAIL_ACTIVITY_EVENT, (event: MailActivityEvent) => schedule(event));

        const connection = socket.connector.pusher.connection;
        const onState = ({ current }: { current: string }) => setConnected(current === 'connected');
        connection.bind('state_change', onState);
        setConnected(connection.state === 'connected');

        return () => {
            connection.unbind('state_change', onState);
            socket.leave(MAIL_ACTIVITY_CHANNEL);
        };
    }, [schedule]);

    useEffect(() => {
        if (connected) {
            return;
        }

        const poll = window.setInterval(() => schedule(null), MAIL_FALLBACK_POLL_MS);

        return () => window.clearInterval(poll);
    }, [connected, schedule]);

    return connected;
}
