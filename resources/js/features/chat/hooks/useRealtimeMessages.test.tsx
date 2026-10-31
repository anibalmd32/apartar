import { LIVE_SAFETY_POLL_MS, mergeById, REALTIME_POLL_MS, useRealtimeMessages } from '@/features/chat/hooks/useRealtimeMessages';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { echoClient } = vi.hoisted(() => ({ echoClient: { current: null as unknown } }));

vi.mock('@/lib/echo', () => ({ echo: () => echoClient.current }));

interface Message {
    readonly id: number;
    readonly body: string;
}

function wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={new QueryClient()}>{children}</QueryClientProvider>;
}

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
});

afterEach(() => {
    vi.useRealTimers();
    echoClient.current = null;
});

function silentLiveClient() {
    return {
        private: () => ({ listen: vi.fn() }),
        leave: vi.fn(),
        connector: { pusher: { connection: { state: 'connected', bind: vi.fn(), unbind: vi.fn() } } },
    };
}

it('une por id, sin repetir y en orden', () => {
    const merged = mergeById<Message>(
        [
            { id: 1, body: 'a' },
            { id: 3, body: 'c' },
        ],
        [
            { id: 2, body: 'b' },
            { id: 3, body: 'c' },
        ],
    );

    expect(merged.map((message) => message.id)).toEqual([1, 2, 3]);
});

it('sin Reverb sondea desde el principio, pidiendo lo posterior al último id', async () => {
    const fetchAfter = vi.fn(async (lastId: number) => (lastId === 1 ? [{ id: 2, body: 'nuevo' }] : []));

    const { result } = renderHook(
        () =>
            useRealtimeMessages<Message>({
                channel: 'organization.7.conversation.42',
                event: 'MessageSent',
                initial: [{ id: 1, body: 'hola' }],
                fetchAfter,
                queryKey: ['chat', 42],
            }),
        { wrapper },
    );

    expect(result.current.mode).toBe('polling');

    await act(async () => {
        await vi.advanceTimersByTimeAsync(10);
    });

    expect(fetchAfter).toHaveBeenCalledWith(1);
    expect(result.current.messages.map((message) => message.id)).toEqual([1, 2]);

    await act(async () => {
        await vi.advanceTimersByTimeAsync(REALTIME_POLL_MS);
    });

    expect(fetchAfter).toHaveBeenLastCalledWith(2);
});

it('lo que se añade a mano y llega luego por el sondeo cuenta una vez', async () => {
    const fetchAfter = vi.fn(async () => [{ id: 5, body: 'enviado' }]);

    const { result } = renderHook(
        () => useRealtimeMessages<Message>({ channel: 'c', event: 'MessageSent', initial: [], fetchAfter, queryKey: ['chat', 'x'] }),
        { wrapper },
    );

    act(() => result.current.add({ id: 5, body: 'enviado' }));

    await act(async () => {
        await vi.advanceTimersByTimeAsync(10);
    });

    expect(result.current.messages).toHaveLength(1);
});

it('conectado en vivo pero sin eventos, los mensajes llegan igual por el sondeo de respaldo', async () => {
    echoClient.current = silentLiveClient();
    let replies: readonly Message[] = [];
    const fetchAfter = vi.fn(async () => replies);

    const { result } = renderHook(
        () =>
            useRealtimeMessages<Message>({
                channel: 'organization.7.conversation.42',
                event: 'MessageSent',
                initial: [{ id: 1, body: 'hola' }],
                fetchAfter,
                queryKey: ['chat', 'silencioso'],
            }),
        { wrapper },
    );

    expect(result.current.mode).toBe('live');

    replies = [{ id: 2, body: 'respuesta del negocio' }];

    await act(async () => {
        await vi.advanceTimersByTimeAsync(LIVE_SAFETY_POLL_MS + 10);
    });

    expect(result.current.messages.map((message) => message.id)).toEqual([1, 2]);
});
