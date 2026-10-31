import { INBOX_REFRESH_MS, useChatInbox } from '@/features/chat/hooks/useChatInbox';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { reload, listen, leave } = vi.hoisted(() => ({ reload: vi.fn(), listen: vi.fn(), leave: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router: { reload } }));
vi.mock('@/lib/echo', () => ({ echo: () => ({ private: () => ({ listen }), leave }) }));

beforeEach(() => {
    vi.useFakeTimers();
    reload.mockReset();
    listen.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
});

it('un mensaje nuevo en el negocio recarga la lista de conversaciones', () => {
    renderHook(() => useChatInbox('organization.7.chat'));

    expect(listen).toHaveBeenCalledWith('MessageSent', expect.any(Function));

    act(() => {
        listen.mock.calls[0]?.[1]();
        vi.advanceTimersByTime(500);
    });

    expect(reload).toHaveBeenCalledWith({ only: ['threads', 'nextPageUrl', 'teamMember'] });
});

it('sin eventos, la lista se refresca sola cada cierto tiempo y deja el canal al salir', () => {
    const { unmount } = renderHook(() => useChatInbox('organization.7.chat'));

    act(() => {
        vi.advanceTimersByTime(INBOX_REFRESH_MS + 500);
    });

    expect(reload).toHaveBeenCalledTimes(1);

    unmount();
    expect(leave).toHaveBeenCalledWith('organization.7.chat');
});
