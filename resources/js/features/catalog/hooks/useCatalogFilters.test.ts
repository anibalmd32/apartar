import { FILTER_PROPS, SEARCH_DEBOUNCE_MS, useCatalogFilters } from '@/features/catalog/hooks/useCatalogFilters';
import { type CatalogFilters, type Paginated } from '@/types/catalog-view';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

/**
 * Búsqueda, filtros y orden en la query string (`REQ-CAT-006` RB-6, RB-7;
 * `REQ-CAT-002` RB-6).
 */

const { get, reload } = vi.hoisted(() => ({ get: vi.fn(), reload: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router: { get, reload } }));

const NONE: CatalogFilters = { q: null, category: null, tags: [], sort: null, ignored: false };

function page(data: string[], nextCursor: string | null = null): Paginated<string> {
    return { data, nextCursor };
}

function hook(filters: CatalogFilters = NONE, products: Paginated<string> = page(['a'], 'cursor-del-lote-2')) {
    return renderHook(({ current, applied }) => useCatalogFilters(current, applied), { initialProps: { current: products, applied: filters } });
}

/** La última visita: la URL y lo que viaja en su query string. */
function lastVisit(): { url: unknown; data: unknown; options: Record<string, unknown> } {
    const [url, data, options] = get.mock.lastCall ?? [];

    return { url, data, options: options as Record<string, unknown> };
}

beforeEach(() => {
    vi.useFakeTimers();
    window.history.replaceState(null, '', '/c/ofertas?category=vieja&cursor=abc');
    get.mockClear();
    reload.mockClear();
});

afterEach(() => {
    vi.useRealTimers();
});

it('recarga solo la parrilla y los filtros, conservando el scroll y el panel (RB-7)', () => {
    const { result } = hook();

    act(() => result.current.setCategory('taladros'));

    expect(FILTER_PROPS).toEqual(['products', 'filters']);
    expect(lastVisit().options).toEqual(expect.objectContaining({ only: ['products', 'filters'], preserveScroll: true, preserveState: true }));
});

/**
 * §7.5 · la visita sale de la ruta y no de la URL actual: ni el cursor del
 * lote que ya se veía ni un filtro que el servidor quitó viajan con ella.
 */
it('cambiar un filtro no manda el cursor y vuelve al primer lote', () => {
    const { result } = hook({ ...NONE, sort: 'price_asc' });

    act(() => result.current.setCategory('taladros'));

    expect(lastVisit().url).toBe('/c/ofertas');
    expect(lastVisit().data).toEqual({ category: 'taladros', sort: 'price_asc' });
});

it('las etiquetas se suman y se quitan; la categoría se sustituye (RB-4)', () => {
    const { result, rerender } = hook({ ...NONE, category: 'taladros', tags: ['oferta'] });

    act(() => result.current.toggleTag('nuevo'));
    expect(lastVisit().data).toEqual({ category: 'taladros', tags: ['oferta', 'nuevo'] });

    rerender({ current: page(['b']), applied: { ...NONE, category: 'taladros', tags: ['oferta', 'nuevo'] } });
    act(() => result.current.toggleTag('oferta'));
    expect(lastVisit().data).toEqual({ category: 'taladros', tags: ['nuevo'] });

    act(() => result.current.setCategory('sierras'));
    expect(lastVisit().data).toEqual({ category: 'sierras', tags: ['oferta', 'nuevo'] });
});

it('espera 300 ms sin teclear antes de buscar, y busca una sola vez (RB-6)', () => {
    const { result } = hook();

    act(() => {
        result.current.setQuery('t');
        result.current.setQuery('ta');
        result.current.setQuery('tala');
    });

    expect(result.current.query).toBe('tala');
    act(() => vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS - 1));
    expect(get).not.toHaveBeenCalled();

    act(() => vi.advanceTimersByTime(1));
    expect(get).toHaveBeenCalledTimes(1);
    expect(lastVisit().data).toEqual({ q: 'tala' });
    // Sustituye la entrada del historial: «atrás» no deshace letra a letra.
    expect(lastVisit().options).toEqual(expect.objectContaining({ replace: true, only: ['products', 'filters'] }));
});

it('un filtro tocado mientras se escribe sale ya con el texto, y el término pendiente no sale después', () => {
    const { result } = hook();

    act(() => {
        result.current.setQuery('taladro');
        result.current.setSort('price_desc');
    });

    expect(lastVisit().data).toEqual({ q: 'taladro', sort: 'price_desc' });

    act(() => vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS * 2));
    expect(get).toHaveBeenCalledTimes(1);
});

it('lo vacío no se escribe en la URL', () => {
    const { result } = hook({ ...NONE, q: 'taladro' });

    act(() => result.current.setQuery('   '));
    act(() => vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS));

    expect(lastVisit().data).toEqual({});
});

/** `REQ-CAT-006` SP-2 y `REQ-CAT-002` SP-2: las dos salidas del estado vacío. */
it('«ver todo» lo quita todo menos el orden, y la categoría del estado vacío empieza de cero', () => {
    const { result } = hook({ q: 'xyz', category: 'taladros', tags: ['oferta'], sort: 'name', ignored: false });

    act(() => result.current.clear());
    expect(lastVisit().data).toEqual({ sort: 'name' });
    expect(result.current.query).toBe('');

    act(() => result.current.showCategory('sierras'));
    expect(lastVisit().data).toEqual({ category: 'sierras', sort: 'name' });
});

it('sabe si la parrilla está acotada', () => {
    expect(hook().result.current.isNarrowed).toBe(false);
    expect(hook({ ...NONE, sort: 'name' }).result.current.isNarrowed).toBe(false);
    expect(hook({ ...NONE, tags: ['oferta'] }).result.current.isNarrowed).toBe(true);
});

/**
 * El resultado de un filtro **sustituye** la lista; solo el lote que pidió
 * «Cargar más» se añade.
 */
it('el resultado de un filtro no se concatena al lote anterior', () => {
    const { result, rerender } = hook(NONE, page(['a', 'b'], 'c2'));

    act(() => result.current.pages.loadMore());
    rerender({ current: page(['c']), applied: NONE });
    expect(result.current.pages.items).toEqual(['a', 'b', 'c']);

    act(() => result.current.setCategory('taladros'));
    rerender({ current: page(['x']), applied: { ...NONE, category: 'taladros' } });
    expect(result.current.pages.items).toEqual(['x']);
});
