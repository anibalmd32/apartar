import { ManualPaymentForm } from '@/features/receipts/components/ManualPaymentForm';
import { RejectForm } from '@/features/receipts/components/RejectForm';
import { PREVIEW_DEBOUNCE_MS } from '@/features/receipts/hooks/useApprovalPreview';
import ReceiptCreate from '@/pages/portal/ReceiptCreate';
import { fakeRoute } from '@/test/fakes';
import { chooseBank, MANUAL_PAYMENT_OPTIONS as OPTIONS } from '@/test/manual-payment-fixtures';
import { receiptProps, stubObjectUrls } from '@/test/receipt-fixtures';
import { PREVIEW } from '@/test/receipt-review-fixtures';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { post, postOptions, usePage } = vi.hoisted(() => ({ post: vi.fn(), postOptions: vi.fn(), usePage: vi.fn() }));

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
                postOptions(options);
                options?.onSuccess?.();
            };

            return { data, setData, reset: () => setState(defaults), errors: {}, processing: false, post: send };
        },
    };
});

function withQueries(node: ReactNode) {
    return render(<QueryClientProvider client={new QueryClient()}>{node}</QueryClientProvider>);
}

stubObjectUrls();

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.stubGlobal('fetch', vi.fn());
    vi.stubGlobal('route', fakeRoute);
    post.mockReset();
    postOptions.mockReset();
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
    chooseBank('Banesco');
    fireEvent.change(screen.getByLabelText('Número de referencia'), { target: { value: '0123456789' } });
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
    expect(sent).toMatchObject({
        method: 'pago_movil',
        bank: 'Banesco',
        reference: '0123456789',
        amount: '160.00',
        currency: 'USD',
        paid_at: '2026-09-11',
        confirm_excess: 1_000,
    });
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
    chooseBank('Banesco');
    fireEvent.change(screen.getByLabelText('Número de referencia'), { target: { value: '0123456789' } });
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

it('pide banco y referencia solo a los métodos que los llevan y manda el comprobante como formulario', async () => {
    vi.mocked(fetch).mockImplementation(async () => new Response(JSON.stringify({ preview: PREVIEW }), { status: 200 }));
    withQueries(<ManualPaymentForm options={OPTIONS} />);
    const record = screen.getByRole('button', { name: 'Registrar el pago' });

    fireEvent.change(screen.getByLabelText('Método'), { target: { value: 'pago_movil' } });
    fireEvent.change(screen.getByLabelText('Importe'), { target: { value: '1275.00' } });
    fireEvent.change(screen.getByLabelText('Nota'), { target: { value: 'Pago móvil confirmado por Ana.' } });
    await act(async () => {
        await vi.advanceTimersByTimeAsync(PREVIEW_DEBOUNCE_MS + 10);
    });
    expect(record).toBeDisabled();

    chooseBank('Banesco');
    fireEvent.change(screen.getByLabelText('Número de referencia'), { target: { value: '0123456789' } });
    fireEvent.change(screen.getByLabelText('Método'), { target: { value: 'efectivo_usd' } });
    expect(screen.queryByLabelText('Banco')).toBeNull();
    expect(screen.queryByLabelText('Número de referencia')).toBeNull();

    const proof = new File(['captura'], 'captura.png', { type: 'image/png' });
    fireEvent.change(screen.getByLabelText(/Elegir archivo/), { target: { files: [proof] } });
    await vi.waitFor(() => expect(record).toBeEnabled());
    fireEvent.click(record);

    expect(post.mock.calls[0]?.[1]).toMatchObject({ method: 'efectivo_usd', bank: '', reference: '', file: proof });
    expect(postOptions.mock.calls[0]?.[0]).toMatchObject({ forceFormData: true });
});

it('no deja registrar con un comprobante de más del máximo', async () => {
    vi.mocked(fetch).mockImplementation(async () => new Response(JSON.stringify({ preview: PREVIEW }), { status: 200 }));
    withQueries(<ManualPaymentForm options={OPTIONS} />);

    fireEvent.change(screen.getByLabelText('Método'), { target: { value: 'efectivo_usd' } });
    fireEvent.change(screen.getByLabelText('Importe'), { target: { value: '30.00' } });
    fireEvent.change(screen.getByLabelText('Nota'), { target: { value: 'Recibido en tienda por Ana.' } });
    const heavy = new File(['x'], 'pesado.pdf', { type: 'application/pdf' });
    Object.defineProperty(heavy, 'size', { value: 6 * 1024 * 1024 });
    fireEvent.change(screen.getByLabelText(/Elegir archivo/), { target: { files: [heavy] } });
    await act(async () => {
        await vi.advanceTimersByTimeAsync(PREVIEW_DEBOUNCE_MS + 10);
    });

    expect(screen.getByText(/Este archivo pasa de 5 MB/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Registrar el pago' })).toBeDisabled();
});

it('el formulario del cliente dice a qué comprobante rechazado sustituye', () => {
    render(<ReceiptCreate {...receiptProps({ replaces: { submittedAt: '2026-10-01', reason: 'La imagen está cortada.' } })} />);

    expect(screen.getByText('Este comprobante sustituye al que enviaste el 01/10/2026, que fue rechazado.')).toBeInTheDocument();
    expect(screen.getByText('Motivo: La imagen está cortada.')).toBeInTheDocument();
    expect(screen.getByText(/no podrás reportarlo otra vez: escríbele a/)).toBeInTheDocument();
});
