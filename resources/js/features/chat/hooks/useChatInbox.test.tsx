import { useChatInbox } from '@/features/chat/hooks/useChatInbox';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { reload, listen, stopListening } = vi.hoisted(() => ({ reload: vi.fn(), listen: vi.fn(), stopListening: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router: { reload } }));
vi.mock('@/lib/echo', () => ({ echo: () => ({ private: () => ({ listen, stopListening }) }) }));

beforeEach(() => {
    vi.useFakeTimers();
    reload.mockReset();
    listen.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
});

it('un mensaje nuevo en el negocio recarga la lista de conversaciones, sin sondear', () => {
    const { unmount } = renderHook(() => useChatInbox('organization.7.chat'));

    act(() => {
        vi.advanceTimersByTime(60_000);
    });
    expect(reload).not.toHaveBeenCalled();

    act(() => {
        listen.mock.calls[0]?.[1]();
        vi.advanceTimersByTime(500);
    });

    expect(reload).toHaveBeenCalledWith({ only: ['threads', 'nextPageUrl', 'teamMember'] });

    unmount();
    expect(stopListening).toHaveBeenCalledWith('MessageSent', listen.mock.calls[0]?.[1]);
});
