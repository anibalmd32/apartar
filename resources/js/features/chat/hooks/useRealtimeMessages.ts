import { echo } from '@/lib/echo';
import { useQuery } from '@tanstack/react-query';
import { useCallback, useEffect, useRef, useState } from 'react';

export const REALTIME_CONNECT_TIMEOUT_MS = 5_000;

export const REALTIME_POLL_MS = 5_000;

export type RealtimeMode = 'connecting' | 'live' | 'polling';

interface Identified {
    readonly id: number;
}

interface UseRealtimeMessagesOptions<T extends Identified> {
    channel: string;
    event: string;
    initial: readonly T[];
    fetchAfter: (lastId: number) => Promise<readonly T[]>;
    queryKey: readonly unknown[];
}

export function useRealtimeMessages<T extends Identified>({ channel, event, initial, fetchAfter, queryKey }: UseRealtimeMessagesOptions<T>) {
    const [messages, setMessages] = useState<readonly T[]>(() => mergeById([], initial));
    const [mode, setMode] = useState<RealtimeMode>(() => (echo() === null || channel === '' ? 'polling' : 'connecting'));
    const lastId = useRef(lastIdOf(messages));

    const merge = useCallback((incoming: readonly T[]) => {
        setMessages((current) => {
            const next = mergeById(current, incoming);
            lastId.current = lastIdOf(next);

            return next;
        });
    }, []);

    useEffect(() => {
        const client = channel === '' ? null : echo();

        if (client === null) {
            return;
        }

        const fallback = window.setTimeout(() => setMode((current) => (current === 'live' ? current : 'polling')), REALTIME_CONNECT_TIMEOUT_MS);
        const subscription = client.private(channel);
        const onMessage = (message: T) => merge([message]);

        subscription.listen(event, onMessage);

        const connection = client.connector.pusher.connection;
        const onState = ({ current }: { current: string }) => {
            if (current === 'connected') {
                window.clearTimeout(fallback);
                setMode('live');
                void fetchAfter(lastId.current).then(merge, () => undefined);

                return;
            }

            if (current === 'unavailable' || current === 'failed' || current === 'disconnected') {
                setMode('polling');
            }
        };

        connection.bind('state_change', onState);

        if (connection.state === 'connected') {
            onState({ current: 'connected' });
        }

        return () => {
            window.clearTimeout(fallback);
            connection.unbind('state_change', onState);
            subscription.stopListening(event, onMessage);
        };
    }, [channel, event, fetchAfter, merge]);

    useQuery({
        queryKey,
        queryFn: async () => {
            const fresh = await fetchAfter(lastId.current);
            merge(fresh);

            return fresh.length;
        },
        enabled: mode !== 'connecting',
        refetchInterval: mode === 'polling' ? REALTIME_POLL_MS : false,
        refetchIntervalInBackground: false,
        refetchOnWindowFocus: 'always',
        retry: false,
    });

    return { messages, mode, add: (...incoming: T[]) => merge(incoming) };
}

export function mergeById<T extends Identified>(current: readonly T[], incoming: readonly T[]): readonly T[] {
    if (incoming.length === 0) {
        return current;
    }

    const byId = new Map<number, T>();

    for (const message of [...current, ...incoming]) {
        byId.set(message.id, message);
    }

    return [...byId.values()].sort((a, b) => a.id - b.id);
}

function lastIdOf(messages: readonly Identified[]): number {
    return messages.length === 0 ? 0 : (messages[messages.length - 1]?.id ?? 0);
}
