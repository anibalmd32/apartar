import { useFollowCatalog } from '@/features/account/hooks/useFollowCatalog';
import { type FollowState } from '@/types/account';
import { act, renderHook } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { post, del } = vi.hoisted(() => ({ post: vi.fn(), del: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router: { post, delete: del } }));

const URL = '/c/ofertas/seguir';

function state(overrides: Partial<FollowState> = {}): FollowState {
    return { signedIn: true, following: false, url: URL, ...overrides };
}

type Options = { onStart: () => void; onFinish: () => void };

function lastOptions(): Options {
    const call = post.mock.calls.at(-1);

    if (call === undefined) {
        throw new Error('No hubo POST.');
    }

    return call[2] as Options;
}

beforeEach(() => {
    post.mockReset();
    del.mockReset();
});

it('sin sesión manda el POST y no finge que ya sigue', () => {
    const { result } = renderHook(() => useFollowCatalog(state({ signedIn: false })));

    act(() => result.current.toggle());

    expect(post).toHaveBeenCalledWith(URL, {}, expect.objectContaining({ preserveScroll: true }));
    expect(result.current.following).toBe(false);
    expect(result.current.needsSignIn).toBe(true);
});

it('con sesión cambia al pulsar y, al confirmar, mandan las props nuevas', () => {
    const { result, rerender } = renderHook((follow: FollowState) => useFollowCatalog(follow), { initialProps: state() });

    act(() => result.current.toggle());
    const options = lastOptions();
    act(() => options.onStart());

    expect(result.current.following).toBe(true);
    expect(result.current.pending).toBe(true);

    rerender(state({ following: true }));
    act(() => options.onFinish());

    expect(result.current.following).toBe(true);
    expect(result.current.pending).toBe(false);
});

it('si la petición no cambia nada, vuelve al valor de las props', () => {
    const { result } = renderHook(() => useFollowCatalog(state()));

    act(() => result.current.toggle());
    const options = lastOptions();
    act(() => options.onFinish());

    expect(result.current.following).toBe(false);
});

it('siguiendo, pulsar deja de seguir con DELETE a la misma URI', () => {
    const { result } = renderHook(() => useFollowCatalog(state({ following: true })));

    act(() => result.current.toggle());

    expect(del).toHaveBeenCalledWith(URL, expect.any(Object));
    expect(result.current.following).toBe(false);
});

it('una segunda pulsación con la primera en vuelo se ignora', () => {
    const { result } = renderHook(() => useFollowCatalog(state()));

    act(() => result.current.toggle());
    act(() => lastOptions().onStart());
    act(() => result.current.toggle());

    expect(post).toHaveBeenCalledTimes(1);
    expect(del).not.toHaveBeenCalled();
});
