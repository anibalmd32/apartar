import { type MailActivityEvent } from '@/features/platform/mail/mailActivity';
import { MAIL_ACTIVITY_DEBOUNCE_MS, MAIL_FALLBACK_POLL_MS, useMailActivity } from '@/features/platform/mail/useMailActivity';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({
    socket: true,
    connection: 'connected',
    page: { component: 'platform/mail/Inbox', props: {} as Record<string, unknown> },
    handlers: new Map<string, (event: unknown) => void>(),
    channels: [] as string[],
    left: [] as string[],
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: { reload: state.reload },
    usePage: () => state.page,
}));

vi.mock('@/lib/echo', () => ({
    echo: () =>
        state.socket
            ? {
                  private: (name: string) => {
                      state.channels.push(name);

                      return { listen: (event: string, handler: (payload: unknown) => void) => state.handlers.set(event, handler) };
                  },
                  leave: (name: string) => state.left.push(name),
                  connector: { pusher: { connection: { state: state.connection, bind: vi.fn(), unbind: vi.fn() } } },
              }
            : null,
}));

const activity = (event: Partial<MailActivityEvent> & Pick<MailActivityEvent, 'kind'>) =>
    act(() => state.handlers.get('MailActivity')?.({ mails: [], threads: [], ...event }));

const flush = () => act(() => vi.advanceTimersByTime(MAIL_ACTIVITY_DEBOUNCE_MS));

function onPage(component: string, props: Record<string, unknown> = {}) {
    state.page = { component, props };
}

beforeEach(() => {
    vi.useFakeTimers();
    state.socket = true;
    state.connection = 'connected';
    state.handlers.clear();
    state.channels = [];
    state.left = [];
    state.reload.mockClear();
    onPage('platform/mail/Inbox');
});

afterEach(() => {
    vi.useRealTimers();
});

it('se suscribe al canal privado de correos y lo deja al desmontar', () => {
    const { unmount } = renderHook(() => useMailActivity());

    expect(state.channels).toEqual(['platform.mail']);

    unmount();

    expect(state.left).toEqual(['platform.mail']);
});

it('junta una ráfaga en una sola recarga parcial de la bandeja y del contador', () => {
    renderHook(() => useMailActivity());

    activity({ kind: 'inbound', threads: [1] });
    activity({ kind: 'inbound', threads: [2] });
    activity({ kind: 'event', mails: ['a'] });

    expect(state.reload).not.toHaveBeenCalled();

    flush();

    expect(state.reload).toHaveBeenCalledTimes(1);
    expect(state.reload).toHaveBeenCalledWith({ only: ['threads', 'hasMore', 'unreadThreads', 'platformOperator'] });
});

it('el detalle de un enviado solo se recarga si el aviso es suyo', () => {
    onPage('platform/mail/SentShow', { mail: { uuid: 'uuid-1' } });
    renderHook(() => useMailActivity());

    activity({ kind: 'event', mails: ['uuid-2'] });
    flush();
    expect(state.reload).not.toHaveBeenCalled();

    activity({ kind: 'event', mails: ['uuid-1'] });
    flush();
    expect(state.reload).toHaveBeenCalledWith({ only: ['mail'] });
});

it('la conversación abierta solo se recarga con lo que le llega a ella', () => {
    onPage('platform/mail/Thread', { thread: { id: 7 } });
    renderHook(() => useMailActivity());

    activity({ kind: 'inbound', threads: [8] });
    flush();
    expect(state.reload).toHaveBeenLastCalledWith({ only: ['platformOperator'] });

    activity({ kind: 'inbound', threads: [7] });
    flush();
    expect(state.reload).toHaveBeenLastCalledWith({ only: ['thread', 'messages', 'platformOperator'] });
});

it('métricas, enviados y el resumen del panel se recargan con sus props', () => {
    onPage('platform/mail/Metrics');
    const { unmount } = renderHook(() => useMailActivity());
    activity({ kind: 'event', mails: ['a'] });
    flush();
    expect(state.reload).toHaveBeenLastCalledWith({ only: ['metrics'] });
    unmount();

    onPage('platform/mail/Sent');
    const sent = renderHook(() => useMailActivity());
    activity({ kind: 'sent', mails: ['a'] });
    flush();
    expect(state.reload).toHaveBeenLastCalledWith({ only: ['mails', 'hasMore', 'platformOperator'] });
    sent.unmount();

    onPage('platform/Dashboard');
    renderHook(() => useMailActivity());
    activity({ kind: 'event' });
    flush();
    expect(state.reload).toHaveBeenLastCalledWith({ only: ['mail'] });
});

it('un aviso de Mailtrap fuera de correos no recarga nada', () => {
    onPage('platform/organizations/Index');
    renderHook(() => useMailActivity());

    activity({ kind: 'event', mails: ['a'] });
    flush();

    expect(state.reload).not.toHaveBeenCalled();
});

it('conectado no sondea', () => {
    renderHook(() => useMailActivity());

    act(() => vi.advanceTimersByTime(MAIL_FALLBACK_POLL_MS * 2 + MAIL_ACTIVITY_DEBOUNCE_MS));

    expect(state.reload).not.toHaveBeenCalled();
});

it('sin Reverb vuelve a sondear cada minuto', () => {
    state.socket = false;
    const { result } = renderHook(() => useMailActivity());

    expect(result.current).toBe(false);
    expect(state.channels).toEqual([]);

    act(() => vi.advanceTimersByTime(MAIL_FALLBACK_POLL_MS + MAIL_ACTIVITY_DEBOUNCE_MS));

    expect(state.reload).toHaveBeenCalledTimes(1);
    expect(state.reload).toHaveBeenCalledWith({ only: ['threads', 'hasMore', 'unreadThreads', 'platformOperator'] });
});
