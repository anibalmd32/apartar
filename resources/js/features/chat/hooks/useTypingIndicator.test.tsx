import { TYPING_EXPIRES_MS, TYPING_THROTTLE_MS, useTypingIndicator } from '@/features/chat/hooks/useTypingIndicator';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { postJson, handlers } = vi.hoisted(() => ({ postJson: vi.fn(async () => ({})), handlers: new Map<string, (event: unknown) => void>() }));

vi.mock('@/lib/json', () => ({ postJson }));
vi.mock('@/lib/echo', () => ({
    echo: () => ({
        private: () => ({
            listen: (event: string, handler: (event: unknown) => void) => handlers.set(event, handler),
            stopListening: () => undefined,
        }),
    }),
}));

beforeEach(() => {
    vi.useFakeTimers();
    postJson.mockClear();
    handlers.clear();
});

afterEach(() => {
    vi.useRealTimers();
});

it('avisa como mucho una vez cada 2 s', () => {
    const { result } = renderHook(() => useTypingIndicator('organization.7.conversation.42', '/escribiendo', 'customer', true));

    act(() => {
        result.current.notify();
        result.current.notify();
    });
    expect(postJson).toHaveBeenCalledTimes(1);

    vi.advanceTimersByTime(TYPING_THROTTLE_MS);
    act(() => result.current.notify());
    expect(postJson).toHaveBeenCalledTimes(2);
});

it('sin socket ni avisa ni escucha', () => {
    const { result } = renderHook(() => useTypingIndicator('organization.7.conversation.42', '/escribiendo', 'customer', false));

    act(() => result.current.notify());

    expect(postJson).not.toHaveBeenCalled();
    expect(handlers.size).toBe(0);
});

it('enseña al otro lado escribiendo y lo apaga a los 4 s; lo propio no se enseña', () => {
    const { result } = renderHook(() => useTypingIndicator('organization.7.conversation.42', '/escribiendo', 'customer', true));

    act(() => handlers.get('UserTyping')?.({ sender: 'customer', name: 'Yo' }));
    expect(result.current.typing).toBeNull();

    act(() => handlers.get('UserTyping')?.({ sender: 'team', name: 'Ana' }));
    expect(result.current.typing).toBe('Ana');

    act(() => vi.advanceTimersByTime(TYPING_EXPIRES_MS));
    expect(result.current.typing).toBeNull();
});
