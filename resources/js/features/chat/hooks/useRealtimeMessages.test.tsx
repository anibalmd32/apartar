import { mergeById, REALTIME_POLL_MS, useRealtimeMessages } from '@/features/chat/hooks/useRealtimeMessages';
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

const subscription = { listen: vi.fn(), stopListening: vi.fn() };

function liveClient() {
    return {
        private: () => subscription,
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

it('en vivo no sondea: el mensaje llega por el evento y al salir solo deja de escuchar', async () => {
    echoClient.current = liveClient();
    subscription.listen.mockClear();
    subscription.stopListening.mockClear();
    const fetchAfter = vi.fn(async () => [] as Message[]);

    const { result, unmount } = renderHook(
        () =>
            useRealtimeMessages<Message>({
                channel: 'organization.7.conversation.42',
                event: 'MessageSent',
                initial: [{ id: 1, body: 'hola' }],
                fetchAfter,
                queryKey: ['chat', 'en-vivo'],
            }),
        { wrapper },
    );

    expect(result.current.mode).toBe('live');

    await act(async () => {
        await vi.advanceTimersByTimeAsync(10);
    });
    const calls = fetchAfter.mock.calls.length;

    await act(async () => {
        await vi.advanceTimersByTimeAsync(REALTIME_POLL_MS * 6);
    });
    expect(fetchAfter.mock.calls.length).toBe(calls);

    const [event, handler] = subscription.listen.mock.calls[0] as [string, (message: Message) => void];
    expect(event).toBe('MessageSent');
    act(() => handler({ id: 2, body: 'respuesta del negocio' }));
    expect(result.current.messages.map((message) => message.id)).toEqual([1, 2]);

    unmount();
    expect(subscription.stopListening).toHaveBeenCalledWith('MessageSent', handler);
});
