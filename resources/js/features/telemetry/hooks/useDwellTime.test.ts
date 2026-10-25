import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import { useDwellTime } from './useDwellTime';

const { track } = vi.hoisted(() => ({ track: vi.fn() }));

vi.mock('./useTelemetry', () => ({ track }));

let visibility: DocumentVisibilityState = 'visible';

function setVisibility(next: DocumentVisibilityState): void {
    visibility = next;
    document.dispatchEvent(new Event('visibilitychange'));
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-21T10:00:00Z'));
    visibility = 'visible';
    vi.spyOn(document, 'visibilityState', 'get').mockImplementation(() => visibility);
    track.mockClear();
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

const options = { path: '/c/tienda/p/taladro', catalogSlug: 'tienda', subjectSlug: 'taladro' };

it('el segundo plano no cuenta: 47 s con 12 ocultos son 35', () => {
    const { unmount } = renderHook(() => useDwellTime(options));

    vi.advanceTimersByTime(20_000);
    setVisibility('hidden');
    vi.advanceTimersByTime(12_000);
    setVisibility('visible');
    vi.advanceTimersByTime(15_000);
    unmount();

    expect(track).toHaveBeenCalledTimes(1);
    expect(track).toHaveBeenCalledWith('dwell_time_recorded', {
        catalogSlug: 'tienda',
        subjectSlug: 'taladro',
        payload: { path: options.path, seconds: 35 },
    });
});

it('menos de cinco segundos no emite', () => {
    const { unmount } = renderHook(() => useDwellTime(options));

    vi.advanceTimersByTime(2_000);
    unmount();

    expect(track).not.toHaveBeenCalled();
});

it('se acota a 3600 segundos', () => {
    const { unmount } = renderHook(() => useDwellTime(options));

    vi.advanceTimersByTime(5 * 3600 * 1000);
    unmount();

    expect(track.mock.calls[0]?.[1].payload.seconds).toBe(3600);
});

it('cambiar de página cierra la medición de la anterior y abre otra', () => {
    const { rerender, unmount } = renderHook((props) => useDwellTime(props), { initialProps: options });

    vi.advanceTimersByTime(10_000);
    rerender({ ...options, path: '/c/tienda/p/sierra', subjectSlug: 'sierra' });
    vi.advanceTimersByTime(8_000);
    unmount();

    expect(track.mock.calls.map((call) => [call[1].subjectSlug, call[1].payload.seconds])).toEqual([
        ['taladro', 10],
        ['sierra', 8],
    ]);
});

it('abandonar la página emite una sola vez', () => {
    const { unmount } = renderHook(() => useDwellTime(options));

    vi.advanceTimersByTime(9_000);
    window.dispatchEvent(new Event('pagehide'));
    unmount();

    expect(track).toHaveBeenCalledTimes(1);
});
