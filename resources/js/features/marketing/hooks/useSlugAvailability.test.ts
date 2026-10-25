import { useSlugAvailability } from '@/features/marketing/hooks/useSlugAvailability';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const getJson = vi.fn();

vi.mock('@/lib/json', () => ({ getJson: (...args: unknown[]) => getJson(...args) }));

beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('route', () => '/registro/enlace');
    getJson.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('dice que está ocupado y cuál está libre', async () => {
    getJson.mockResolvedValue({ slug: 'boutique-mariela', available: false, suggestion: 'boutique-mariela-2' });

    const { result } = renderHook(() => useSlugAvailability('boutique-mariela'));

    await act(async () => {
        await vi.advanceTimersByTimeAsync(500);
    });

    expect(getJson).toHaveBeenCalledWith('/registro/enlace?slug=boutique-mariela');
    expect(result.current).toEqual({ state: 'taken', slug: 'boutique-mariela', suggestion: 'boutique-mariela-2' });
});

it('con el campo vacío no pregunta', async () => {
    const { result } = renderHook(() => useSlugAvailability('  '));

    await act(async () => {
        await vi.advanceTimersByTimeAsync(500);
    });

    expect(getJson).not.toHaveBeenCalled();
    expect(result.current).toEqual({ state: 'idle' });
});
