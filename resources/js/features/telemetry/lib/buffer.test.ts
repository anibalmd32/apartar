import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import { type TelemetryEvent } from '../events';
import { FLUSH_EVERY_MS, MAX_BATCH, RATE_LIMIT_PAUSE_MS, STORAGE_KEY, TelemetryBuffer, type Transport } from './buffer';

const event = (index = 0): TelemetryEvent => ({
    name: 'catalog_viewed',
    occurredAt: `2026-10-21T10:00:${String(index % 60).padStart(2, '0')}Z`,
    catalogSlug: 'tienda',
});

function eventsIn(transport: ReturnType<typeof vi.fn>, call = 0): number {
    return (JSON.parse(transport.mock.calls[call]?.[1] as string) as { events: unknown[] }).events.length;
}

beforeEach(() => {
    sessionStorage.clear();
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
});

it('envía al llegar a diez eventos', async () => {
    const transport = vi.fn<Transport>().mockResolvedValue(204);
    const buffer = new TelemetryBuffer({ endpoint: '/api/telemetry', transport, beacon: null });

    for (let index = 0; index < 9; index++) buffer.push(event(index));
    expect(transport).not.toHaveBeenCalled();

    buffer.push(event(9));
    await vi.waitFor(() => expect(transport).toHaveBeenCalledTimes(1));
    expect(eventsIn(transport)).toBe(10);
});

it('envía cada quince segundos y al abandonar con sendBeacon', async () => {
    const transport = vi.fn<Transport>().mockResolvedValue(204);
    const beacon = vi.fn().mockReturnValue(true);
    const buffer = new TelemetryBuffer({ endpoint: '/api/telemetry', transport, beacon });
    const stop = buffer.start();

    buffer.push(event());
    await vi.advanceTimersByTimeAsync(FLUSH_EVERY_MS);
    expect(transport).toHaveBeenCalledTimes(1);

    buffer.push(event(1));
    window.dispatchEvent(new Event('pagehide'));
    expect(beacon).toHaveBeenCalledWith('/api/telemetry', expect.stringContaining('"catalog_viewed"'));

    stop();
});

it('sin sendBeacon, fetch con keepalive', () => {
    const transport = vi.fn<Transport>().mockResolvedValue(204);
    const buffer = new TelemetryBuffer({ endpoint: '/api/telemetry', transport, beacon: null });

    buffer.push(event());
    buffer.flushOnLeave();

    expect(transport).toHaveBeenCalledWith('/api/telemetry', expect.any(String), true);
});

it('parte doscientos eventos en lotes de cincuenta', async () => {
    const transport = vi.fn<Transport>().mockResolvedValue(204);
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(Array.from({ length: 200 }, (_, index) => event(index))));
    const buffer = new TelemetryBuffer({ endpoint: '/api/telemetry', transport, beacon: null });

    await buffer.flush();

    expect(transport).toHaveBeenCalledTimes(4);
    expect(eventsIn(transport, 3)).toBe(MAX_BATCH);
});

it('respalda en sessionStorage y lo limpia al enviar', async () => {
    const transport = vi.fn<Transport>().mockResolvedValue(204);
    const buffer = new TelemetryBuffer({ endpoint: '/api/telemetry', transport, beacon: null });

    buffer.push(event());
    expect(JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? '[]')).toHaveLength(1);
    expect(new TelemetryBuffer({ endpoint: '/x', transport, beacon: null }).size).toBe(1);

    await buffer.flush();
    expect(sessionStorage.getItem(STORAGE_KEY)).toBeNull();
});

it('con sessionStorage bloqueado vive en memoria', async () => {
    const transport = vi.fn<Transport>().mockResolvedValue(204);
    const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new Error('bloqueado');
    });
    const buffer = new TelemetryBuffer({ endpoint: '/api/telemetry', transport, beacon: null });

    expect(() => buffer.push(event())).not.toThrow();
    await buffer.flush();
    expect(transport).toHaveBeenCalledTimes(1);

    setItem.mockRestore();
});

it('un fallo de red se reintenta una vez y se descarta sin propagar', async () => {
    const transport = vi.fn<Transport>().mockRejectedValue(new TypeError('sin red'));
    const buffer = new TelemetryBuffer({ endpoint: '/api/telemetry', transport, beacon: null });

    buffer.push(event());
    await expect(buffer.flush()).resolves.toBeUndefined();

    expect(transport).toHaveBeenCalledTimes(2);
    expect(buffer.size).toBe(0);
});

it('un 429 pausa los envíos sesenta segundos y conserva los eventos', async () => {
    let now = 1_000_000;
    const transport = vi.fn<Transport>().mockResolvedValueOnce(429).mockResolvedValue(204);
    const buffer = new TelemetryBuffer({ endpoint: '/api/telemetry', transport, beacon: null, now: () => now });

    buffer.push(event());
    await buffer.flush();
    expect(buffer.size).toBe(1);

    await buffer.flush();
    expect(transport).toHaveBeenCalledTimes(1);

    now += RATE_LIMIT_PAUSE_MS;
    await buffer.flush();
    expect(transport).toHaveBeenCalledTimes(2);
    expect(buffer.size).toBe(0);
});
