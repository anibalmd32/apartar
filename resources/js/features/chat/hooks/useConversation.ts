import { useRealtimeMessages } from '@/features/chat/hooks/useRealtimeMessages';
import { getJson, JsonRequestError, postJson } from '@/lib/json';
import { type ChatMessage, type ChatThread, type MessageSenderType } from '@/types/chat';
import { useCallback, useMemo, useState } from 'react';

export interface OutgoingMessage {
    readonly key: string;
    readonly body: string;
    readonly status: 'sending' | 'failed';
    readonly error: string | null;
    readonly id?: number;
}

export function useConversation(thread: ChatThread, me: MessageSenderType) {
    const [outgoing, setOutgoing] = useState<readonly OutgoingMessage[]>([]);

    const fetchAfter = useCallback((lastId: number) => getJson<ChatMessage[]>(`${thread.messagesUrl}?after=${lastId}`), [thread.messagesUrl]);
    const queryKey = useMemo(() => ['chat', thread.messagesUrl], [thread.messagesUrl]);

    const realtime = useRealtimeMessages<ChatMessage>({
        channel: thread.channel ?? '',
        event: 'MessageSent',
        initial: thread.messages,
        fetchAfter,
        queryKey,
    });

    const deliver = useCallback(
        async (key: string, body: string) => {
            setOutgoing((current) => [...current.filter((item) => item.key !== key), { key, body, status: 'sending', error: null }]);

            try {
                const { id } = await postJson<{ id: number }>(thread.sendUrl, { body, idempotencyKey: key });
                setOutgoing((current) => current.map((item) => (item.key === key ? { ...item, id } : item)));
                const fresh = await fetchAfter(lastIdOf(realtime.messages));
                realtime.add(...fresh);
                setOutgoing((current) => current.filter((item) => item.key !== key || !fresh.some((message) => message.id === id)));
            } catch (error) {
                const message = error instanceof JsonRequestError ? error.message : 'No se pudo enviar. Revisa tu conexión.';
                setOutgoing((current) => current.map((item) => (item.key === key ? { ...item, status: 'failed', error: message } : item)));
            }
        },
        [fetchAfter, realtime, thread.sendUrl],
    );

    const delivered = new Set(realtime.messages.map((message) => message.id));

    return {
        messages: realtime.messages,
        mode: realtime.mode,
        outgoing: outgoing.filter((item) => item.id === undefined || !delivered.has(item.id)),
        mine: (message: ChatMessage) => message.sender === me,
        send: (body: string) => {
            const text = body.trim();

            if (text !== '') {
                void deliver(crypto.randomUUID(), text);
            }
        },
        retry: (key: string) => {
            const item = outgoing.find((candidate) => candidate.key === key);

            if (item !== undefined) {
                void deliver(item.key, item.body);
            }
        },
    };
}

function lastIdOf(messages: readonly ChatMessage[]): number {
    return messages.length === 0 ? 0 : (messages[messages.length - 1]?.id ?? 0);
}
