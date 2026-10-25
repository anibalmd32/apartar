import { ManualPaymentForm } from '@/features/receipts/components/ManualPaymentForm';
import { RejectForm } from '@/features/receipts/components/RejectForm';
import { PREVIEW_DEBOUNCE_MS } from '@/features/receipts/hooks/useApprovalPreview';
import ReceiptCreate from '@/pages/portal/ReceiptCreate';
import { fakeRoute } from '@/test/fakes';
import { receiptProps, stubObjectUrls } from '@/test/receipt-fixtures';
import { PREVIEW } from '@/test/receipt-review-fixtures';
import { type ManualPaymentOptions } from '@/types/layaway-backoffice';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen } from '@testing-library/react';
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

            return { data, setData, reset: () => setState(defaults), errors: {}, processing: false, post: send };
        },
    };
});

const OPTIONS: ManualPaymentOptions = {
    storeUrl: '/backoffice/apartados/AP-7K3M9QRT/pagos',
    previewUrl: '/backoffice/apartados/AP-7K3M9QRT/pagos/vista-previa',
    methods: [
        { value: 'efectivo_usd', label: 'Efectivo en dólares', defaultCurrency: 'USD' },
        { value: 'pago_movil', label: 'Pago móvil', defaultCurrency: 'VES' },
    ],
    paymentAccounts: [],
    currencies: [
        { value: 'USD', label: 'Dólares' },
        { value: 'VES', label: 'Bolívares' },
    ],
    paidAt: { min: '2026-09-10', max: '2026-09-11' },
    rate: { rate: '42.50000000', sourceLabel: 'BCV' },
};

function withQueries(node: ReactNode) {
    return render(<QueryClientProvider client={new QueryClient()}>{node}</QueryClientProvider>);
}

stubObjectUrls();

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.stubGlobal('fetch', vi.fn());
    vi.stubGlobal('route', fakeRoute);
    post.mockReset();
    usePage.mockReturnValue({
        props: { auth: { portal: { fullName: 'María González' } }, currency: { display: 'usd_primary', rate: { rate: null } }, errors: {} },
    });
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('rechaza con un motivo y una explicación de al menos diez caracteres', () => {
    render(
        <RejectForm
            rejection={{ url: '/backoffice/comprobantes/9/rechazar', codes: [{ value: 'not_legible', label: 'No se lee el comprobante' }] }}
        />,
    );

    const reject = screen.getByRole('button', { name: 'Rechazar el comprobante' });
    expect(reject).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Motivo'), { target: { value: 'not_legible' } });
    fireEvent.change(screen.getByLabelText('Explicación para el cliente'), { target: { value: 'Corta' } });
    expect(reject).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Explicación para el cliente'), { target: { value: 'La imagen está cortada.' } });
    fireEvent.click(reject);

    expect(post).toHaveBeenCalledWith('/backoffice/comprobantes/9/rechazar', { code: 'not_legible', reason: 'La imagen está cortada.' });
});

it('registra el pago con su clave, tras confirmar el sobrante', async () => {
    vi.mocked(fetch).mockResolvedValue(
        new Response(JSON.stringify({ preview: { ...PREVIEW, unallocated: '$10,00', unallocatedCents: 1_000 } }), { status: 200 }),
    );
    withQueries(<ManualPaymentForm options={OPTIONS} />);

    fireEvent.change(screen.getByLabelText('Método'), { target: { value: 'pago_movil' } });
    expect(screen.getByLabelText('Moneda')).toHaveValue('VES');
    fireEvent.change(screen.getByLabelText('Moneda'), { target: { value: 'USD' } });
    fireEvent.change(screen.getByLabelText('Importe'), { target: { value: '160.00' } });
    fireEvent.change(screen.getByLabelText('Nota'), { target: { value: 'Recibido en tienda por Ana.' } });

    await act(async () => {
        await vi.advanceTimersByTimeAsync(PREVIEW_DEBOUNCE_MS + 10);
    });

    expect(vi.mocked(fetch).mock.calls.at(-1)?.[0]).toBe('/backoffice/apartados/AP-7K3M9QRT/pagos/vista-previa?amount=160.00&currency=USD');
    const record = screen.getByRole('button', { name: 'Registrar el pago' });
    expect(record).toBeDisabled();

    fireEvent.click(await screen.findByLabelText('Confirmo que registro el pago con $10,00 sin asignar'));
    fireEvent.click(record);

    const [url, sent] = post.mock.calls[0] as [string, Record<string, unknown>];
    expect(url).toBe(OPTIONS.storeUrl);
    expect(sent).toMatchObject({ method: 'pago_movil', amount: '160.00', currency: 'USD', paid_at: '2026-09-11', confirm_excess: 1_000 });
    expect(sent.idempotency_key).toMatch(/^[0-9a-f-]{36}$/);
});

it('tras registrar, el formulario vuelve a empezar con otra clave', async () => {
    vi.mocked(fetch).mockImplementation(async () => new Response(JSON.stringify({ preview: PREVIEW }), { status: 200 }));
    withQueries(<ManualPaymentForm options={OPTIONS} />);
    const record = async () => {
        fireEvent.change(screen.getByLabelText('Método'), { target: { value: 'efectivo_usd' } });
        fireEvent.change(screen.getByLabelText('Importe'), { target: { value: '30.00' } });
        fireEvent.change(screen.getByLabelText('Nota'), { target: { value: 'Recibido en tienda por Ana.' } });
        await act(async () => {
            await vi.advanceTimersByTimeAsync(PREVIEW_DEBOUNCE_MS + 10);
        });
        await vi.waitFor(() => expect(screen.getByRole('button', { name: 'Registrar el pago' })).toBeEnabled());
        fireEvent.click(screen.getByRole('button', { name: 'Registrar el pago' }));
    };

    await record();
    expect(screen.getByLabelText('Importe')).toHaveValue('');
    await record();

    const [first, second] = post.mock.calls.map(([, sent]) => (sent as Record<string, unknown>).idempotency_key);
    expect(first).toMatch(/^[0-9a-f-]{36}$/);
    expect(second).toMatch(/^[0-9a-f-]{36}$/);
    expect(second).not.toBe(first);
});

it('pide confirmar una tasa de más de 30 días y manda su publicación', async () => {
    const staleRate = { token: 'bcv@40.00000000@1753000000', days: 45, sourceLabel: 'BCV' };
    vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify({ preview: { ...PREVIEW, staleRate } }), { status: 200 }));
    withQueries(<ManualPaymentForm options={OPTIONS} />);

    fireEvent.change(screen.getByLabelText('Método'), { target: { value: 'pago_movil' } });
    fireEvent.change(screen.getByLabelText('Importe'), { target: { value: '1200.00' } });
    fireEvent.change(screen.getByLabelText('Nota'), { target: { value: 'Recibido en tienda por Ana.' } });
    await act(async () => {
        await vi.advanceTimersByTimeAsync(PREVIEW_DEBOUNCE_MS + 10);
    });

    const record = screen.getByRole('button', { name: 'Registrar el pago' });
    expect(await screen.findByText(/La tasa BCV se publicó hace 45 días/)).toBeInTheDocument();
    expect(record).toBeDisabled();

    fireEvent.click(screen.getByLabelText('Confirmo que convierto con la tasa de hace 45 días'));
    fireEvent.click(record);

    expect(post.mock.calls[0]?.[1]).toMatchObject({ currency: 'VES', confirm_stale_rate: 'bcv@40.00000000@1753000000' });
});

it('el formulario del cliente dice a qué comprobante rechazado sustituye', () => {
    render(<ReceiptCreate {...receiptProps({ replaces: { submittedAt: '2026-10-01', reason: 'La imagen está cortada.' } })} />);

    expect(screen.getByText('Este comprobante sustituye al que enviaste el 01/10/2026, que fue rechazado.')).toBeInTheDocument();
    expect(screen.getByText('Motivo: La imagen está cortada.')).toBeInTheDocument();
    expect(screen.getByText(/no podrás reportarlo otra vez: escríbele a/)).toBeInTheDocument();
});
