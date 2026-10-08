import { PENDING_RECEIPTS_ENDPOINT, PENDING_RECEIPTS_POLL_MS, usePendingReceipts } from '@/features/receipts/hooks/useReceiptInbox';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

/**
 * El contador de la bandeja (`REQ-PAY-004` RB-3, SP-1).
 *
 * Su razón de ser es que **el menú y la bandeja digan lo mismo**, así que los
 * casos montan dos copias bajo el mismo `QueryClient` y afirman sobre la
 * segunda: con una sola, un contador por copia pasaría igual.
 */

function Counter({ label, shared }: { label: string; shared: number | null }) {
    const pending = usePendingReceipts(shared);

    return <output aria-label={label}>{pending === null ? 'sin cifra' : String(pending)}</output>;
}

function renderBoth(shared: number | null) {
    const client = new QueryClient();
    const wrap = (node: ReactNode) => <QueryClientProvider client={client}>{node}</QueryClientProvider>;

    const view = render(
        wrap(
            <>
                <Counter label="menú" shared={shared} />
                <Counter label="bandeja" shared={shared} />
            </>,
        ),
    );

    return {
        ...view,
        rerenderWith: (next: number | null) =>
            view.rerender(
                wrap(
                    <>
                        <Counter label="menú" shared={next} />
                        <Counter label="bandeja" shared={next} />
                    </>,
                ),
            ),
    };
}

function respond(pending: number, status = 200) {
    vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify({ pending, newest: null }), { status }));
}

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('empieza con la cifra de la respuesta de Inertia y no pregunta enseguida', () => {
    renderBoth(3);

    expect(screen.getByLabelText('bandeja')).toHaveTextContent('3');
    expect(fetch).not.toHaveBeenCalled();
});

it('cada 30 s pregunta el contador una sola vez, y las dos copias cambian a la vez', async () => {
    respond(5);
    renderBoth(3);

    await act(async () => {
        await vi.advanceTimersByTimeAsync(PENDING_RECEIPTS_POLL_MS + 10);
    });

    expect(fetch).toHaveBeenCalledTimes(1);
    expect(vi.mocked(fetch).mock.calls[0]?.[0]).toBe(PENDING_RECEIPTS_ENDPOINT);
    expect(screen.getByLabelText('menú')).toHaveTextContent('5');
    expect(screen.getByLabelText('bandeja')).toHaveTextContent('5');
});

it('si el sondeo falla, se queda la última cifra buena', async () => {
    respond(0, 500);
    renderBoth(4);

    await act(async () => {
        await vi.advanceTimersByTimeAsync(PENDING_RECEIPTS_POLL_MS + 10);
    });

    expect(fetch).toHaveBeenCalled();
    expect(screen.getByLabelText('bandeja')).toHaveTextContent('4');
});

it('una respuesta de Inertia más nueva manda sobre lo que dijo el sondeo', async () => {
    respond(9);
    const { rerenderWith } = renderBoth(3);

    await act(async () => {
        await vi.advanceTimersByTimeAsync(PENDING_RECEIPTS_POLL_MS + 10);
    });
    expect(screen.getByLabelText('bandeja')).toHaveTextContent('9');

    // El efecto corre al salir del `act` del rerender, y react-query avisa a
    // los observadores en el tick siguiente.
    rerenderWith(2);
    await act(async () => {
        await vi.advanceTimersByTimeAsync(1);
    });

    expect(screen.getByLabelText('menú')).toHaveTextContent('2');
    expect(screen.getByLabelText('bandeja')).toHaveTextContent('2');
});

/**
 * Un sondeo que salió antes de una respuesta de Inertia más nueva no puede
 * volver después y pisar su cifra con la vieja.
 */
it('una respuesta de Inertia cancela el sondeo que estaba en vuelo', async () => {
    let answer: (response: Response) => void = () => undefined;
    vi.mocked(fetch).mockReturnValue(new Promise<Response>((resolve) => (answer = resolve)));
    const { rerenderWith } = renderBoth(5);

    await act(async () => {
        await vi.advanceTimersByTimeAsync(PENDING_RECEIPTS_POLL_MS + 10);
    });
    expect(fetch).toHaveBeenCalledTimes(1);

    rerenderWith(6);
    await act(async () => {
        answer(new Response(JSON.stringify({ pending: 5, newest: null }), { status: 200 }));
        await vi.advanceTimersByTimeAsync(1);
    });

    expect(screen.getByLabelText('menú')).toHaveTextContent('6');
    expect(screen.getByLabelText('bandeja')).toHaveTextContent('6');
});

it('sin cifra que enseñar —sin la capacidad— no sondea nada', async () => {
    renderBoth(null);

    await act(async () => {
        await vi.advanceTimersByTimeAsync(PENDING_RECEIPTS_POLL_MS * 3);
    });

    expect(fetch).not.toHaveBeenCalled();
    expect(screen.getByLabelText('bandeja')).toHaveTextContent('sin cifra');
});
