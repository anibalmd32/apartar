import { ManualPaymentForm } from '@/features/receipts/components/ManualPaymentForm';
import { PREVIEW_DEBOUNCE_MS } from '@/features/receipts/hooks/useApprovalPreview';
import { fakeRoute } from '@/test/fakes';
import { MANUAL_PAYMENT_OPTIONS as OPTIONS } from '@/test/manual-payment-fixtures';
import { PREVIEW } from '@/test/receipt-review-fixtures';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { post, usePage } = vi.hoisted(() => ({ post: vi.fn(), usePage: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        usePage,
        Head: () => null,
        Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [defaults] = useState(initial);
            const [data, setState] = useState(initial);
            const setData = (key: keyof T | ((previous: T) => T), value?: unknown) =>
                setState((previous) => (typeof key === 'function' ? key(previous) : { ...previous, [key]: value }));

            const send = (url: string, options?: { onSuccess?: () => void }) => {
                post(url, data);
                options?.onSuccess?.();
            };

            return { data, setData, reset: () => setState(defaults), clearErrors: vi.fn(), errors: {}, processing: false, post: send };
        },
    };
});

function withQueries(node: ReactNode) {
    return render(<QueryClientProvider client={new QueryClient()}>{node}</QueryClientProvider>);
}

const EXCESS_PREVIEW = { ...PREVIEW, unallocated: '$10,00', unallocatedCents: 1_000 };

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.stubGlobal('fetch', vi.fn());
    vi.stubGlobal('route', fakeRoute);
    post.mockReset();
    usePage.mockReturnValue({ props: { errors: {} } });
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

async function fillWithExcess() {
    vi.mocked(fetch).mockImplementation(async () => new Response(JSON.stringify({ preview: EXCESS_PREVIEW }), { status: 200 }));
    withQueries(<ManualPaymentForm options={OPTIONS} open onClose={() => undefined} title="Registrar pago manual" />);

    fireEvent.change(screen.getByLabelText('Método'), { target: { value: 'efectivo_usd' } });
    fireEvent.change(screen.getByLabelText('Importe'), { target: { value: '160,00' } });
    fireEvent.change(screen.getByLabelText('Nota (obligatoria)', { selector: 'textarea[name="note"]' }), {
        target: { value: 'Recibido en tienda por Ana.' },
    });

    await act(async () => {
        await vi.advanceTimersByTimeAsync(PREVIEW_DEBOUNCE_MS + 10);
    });

    fireEvent.click(await screen.findByLabelText('Confirmo que registro el pago con $10,00 sin asignar'));
}

it('el selector del sobrante aparece cuando el pago deja sobra', async () => {
    await fillWithExcess();

    expect(screen.getByLabelText('¿A dónde va lo que sobra?')).toHaveValue('');
    expect(screen.getByText('Sin asignar')).toBeInTheDocument();
    expect(screen.queryByLabelText('Persona del equipo')).toBeNull();
    expect(screen.queryByLabelText('Nota del destino (obligatoria)')).toBeNull();
});

it('elegir a una persona del equipo muestra el equipo y manda su id', async () => {
    await fillWithExcess();

    fireEvent.change(screen.getByLabelText('¿A dónde va lo que sobra?'), { target: { value: 'team_member' } });

    const people = screen.getByLabelText('Persona del equipo');

    expect(people).toHaveValue('');
    expect(within(people).getByRole('option', { name: 'Irma Rojas' })).toBeInTheDocument();

    fireEvent.change(people, { target: { value: '7' } });
    fireEvent.click(screen.getByRole('button', { name: 'Registrar el pago' }));

    expect(post.mock.calls[0]?.[1]).toMatchObject({ surplus_destination: 'team_member', surplus_user_id: '7' });
});

it('no deja registrar mientras no se elija a quién va el sobrante', async () => {
    await fillWithExcess();

    fireEvent.change(screen.getByLabelText('¿A dónde va lo que sobra?'), { target: { value: 'team_member' } });

    expect(screen.getByRole('button', { name: 'Registrar el pago' })).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Persona del equipo'), { target: { value: '7' } });

    expect(screen.getByRole('button', { name: 'Registrar el pago' })).toBeEnabled();
});

it('«Otro» pide una nota y la manda', async () => {
    await fillWithExcess();

    fireEvent.change(screen.getByLabelText('¿A dónde va lo que sobra?'), { target: { value: 'other' } });

    expect(screen.getByLabelText('Nota del destino (obligatoria)')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Registrar el pago' })).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Nota del destino (obligatoria)'), { target: { value: 'Un paquete de load' } });
    fireEvent.click(screen.getByRole('button', { name: 'Registrar el pago' }));

    expect(post.mock.calls[0]?.[1]).toMatchObject({ surplus_destination: 'other', surplus_note: 'Un paquete de load' });
});

it('las destinos fijos no piden nada más', async () => {
    await fillWithExcess();

    fireEvent.change(screen.getByLabelText('¿A dónde va lo que sobra?'), { target: { value: 'tip' } });

    expect(screen.queryByLabelText('Persona del equipo')).toBeNull();
    expect(screen.queryByLabelText('Nota del destino (obligatoria)')).toBeNull();
    expect(screen.getByRole('button', { name: 'Registrar el pago' })).toBeEnabled();

    fireEvent.click(screen.getByRole('button', { name: 'Registrar el pago' }));

    expect(post.mock.calls[0]?.[1]).toMatchObject({ surplus_destination: 'tip' });
});
