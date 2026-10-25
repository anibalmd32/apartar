import { useCursorPages } from '@/features/catalog/hooks/useCursorPages';
import { type CursorPage } from '@/types/catalog-view';
import { act, renderHook } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

const { reload } = vi.hoisted(() => ({ reload: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router: { reload } }));

function page(data: string[], nextCursor: string | null): CursorPage<string> {
    return { data, nextCursor };
}

it('pide solo la prop de la lista, con el cursor y sin tocar la URL', () => {
    const { result } = renderHook(() => useCursorPages(page(['a'], 'abc'), 'products'));

    act(() => result.current.loadMore());

    expect(reload).toHaveBeenCalledWith(
        expect.objectContaining({
            only: ['products'],
            data: { cursor: 'abc' },
            preserveUrl: true,
        }),
    );
});

it('añade el lote que pidió a lo que ya se veía', () => {
    const { result, rerender } = renderHook(({ current }) => useCursorPages(current, 'products'), {
        initialProps: { current: page(['a', 'b'], 'abc') },
    });

    act(() => result.current.loadMore());
    rerender({ current: page(['c'], null) });

    expect(result.current.items).toEqual(['a', 'b', 'c']);
    expect(result.current.hasMore).toBe(false);
});

it('sustituye la lista cuando la prop llega sin haberla pedido', () => {
    const { result, rerender } = renderHook(({ current }) => useCursorPages(current, 'products'), {
        initialProps: { current: page(['a', 'b'], 'abc') },
    });

    rerender({ current: page(['x'], null) });

    expect(result.current.items).toEqual(['x']);
});

it('no pide nada cuando ya no queda', () => {
    const { result } = renderHook(() => useCursorPages(page(['a'], null), 'products'));

    act(() => result.current.loadMore());

    expect(reload).not.toHaveBeenCalled();
    expect(result.current.hasMore).toBe(false);
});

it('si el lote no llega, la siguiente prop no se añade', () => {
    const { result, rerender } = renderHook(({ current }) => useCursorPages(current, 'products'), {
        initialProps: { current: page(['a'], 'abc') },
    });

    act(() => result.current.loadMore());
    act(() => reload.mock.calls[0]?.[0].onError());
    rerender({ current: page(['x'], null) });

    expect(result.current.items).toEqual(['x']);
});
