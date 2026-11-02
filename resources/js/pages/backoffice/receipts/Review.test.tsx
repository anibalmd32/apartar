import { PREVIEW_DEBOUNCE_MS } from '@/features/receipts/hooks/useApprovalPreview';
import ReceiptReview from '@/pages/backoffice/receipts/Review';
import { PREVIEW, reviewProps as props } from '@/test/receipt-review-fixtures';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { post, formErrors, processing } = vi.hoisted(() => ({
    post: vi.fn(),
    formErrors: { current: {} as Record<string, string> },
    processing: { current: false },
}));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        usePage: () => ({ props: { errors: {}, currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: { rate: null } } } }),
        Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
            <a href={href} className={className}>
                {children}
            </a>
        ),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = useState(initial);

            const setData = (key: keyof T | ((previous: T) => T), value?: unknown) =>
                setState((previous) => (typeof key === 'function' ? key(previous) : { ...previous, [key]: value }));

            return { data, setData, errors: formErrors.current, processing: processing.current, post };
        },
    };
});

vi.mock('@/layouts/backoffice-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <QueryClientProvider client={new QueryClient()}>{children}</QueryClientProvider>,
}));

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.stubGlobal('fetch', vi.fn());
    post.mockReset();
    formErrors.current = {};
    processing.current = false;
});

async function settle() {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(PREVIEW_DEBOUNCE_MS + 10);
    });
}

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('pone juntos el archivo, lo declarado, el cronograma y lo que aplicaría FIFO', () => {
    render(<ReceiptReview {...props()} />);

    expect(screen.getByRole('img', { name: 'Captura del comprobante' })).toHaveAttribute('src', '/backoffice/comprobantes/9/archivo?signature=x');
    expect(screen.getByText(/Mercantil · Ref\. 00042/)).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Cronograma' })).toBeInTheDocument();
    expect(screen.getByText('Se aplicará a las cuotas 1 y 2.')).toBeInTheDocument();
    expect(fetch).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Aprobar y aplicar el pago' }));
    expect(post).toHaveBeenCalledWith('/backoffice/comprobantes/9/aprobar', expect.anything());
});

it('con sobrante exige la confirmación antes de aprobar', () => {
    render(
        <ReceiptReview
            {...props({
                preview: {
                    ...PREVIEW,
                    unallocated: '$10,00',
                    unallocatedCents: 1_000,
                    summary: 'Se aplicará a la cuota 1; sobrarán $10,00 sin asignar.',
                },
            })}
        />,
    );

    const approve = screen.getByRole('button', { name: 'Aprobar y aplicar el pago' });
    expect(approve).toBeDisabled();

    fireEvent.click(screen.getByLabelText('Confirmo que apruebo con $10,00 sin asignar'));

    expect(approve).toBeEnabled();
});

it('al cambiar el importe pide la vista previa al servidor', async () => {
    vi.mocked(fetch).mockResolvedValue(
        new Response(JSON.stringify({ preview: { ...PREVIEW, amount: '$30,00', summary: 'Se aplicará a la cuota 1.' } }), { status: 200 }),
    );
    render(<ReceiptReview {...props()} />);

    fireEvent.change(screen.getByLabelText('Importe recibido'), { target: { value: '1275,00' } });
    await act(async () => {
        await vi.advanceTimersByTimeAsync(PREVIEW_DEBOUNCE_MS + 10);
    });

    expect(vi.mocked(fetch).mock.calls[0]?.[0]).toBe('/backoffice/comprobantes/9/vista-previa?amount=1.275%2C00&currency=VES');
    expect(await screen.findByText('Se aplicará a la cuota 1.')).toBeInTheDocument();
});

it('pinta el motivo cuando no hay vista previa posible', async () => {
    vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify({ message: 'La inicial se paga entera para activarlo.' }), { status: 422 }));
    render(<ReceiptReview {...props()} />);

    fireEvent.change(screen.getByLabelText('Importe recibido'), { target: { value: '10.00' } });
    await act(async () => {
        await vi.advanceTimersByTimeAsync(PREVIEW_DEBOUNCE_MS + 10);
    });

    expect(await screen.findByText('La inicial se paga entera para activarlo.')).toBeInTheDocument();
});

it('uno ya resuelto se ve sin formulario, con quién lo resolvió', () => {
    render(
        <ReceiptReview
            {...props({
                approval: null,
                preview: null,
                receipt: { ...props().receipt, status: 'rejected', statusLabel: 'Rechazado', reviewedBy: 'Ana Pérez', rejectionReason: 'No se lee.' },
            })}
        />,
    );

    expect(screen.queryByRole('button', { name: 'Aprobar y aplicar el pago' })).not.toBeInTheDocument();
    expect(screen.getByText('Rechazado por Ana Pérez. Motivo: No se lee.')).toBeInTheDocument();
});

it('si el sobrante cambia, hay que volver a confirmarlo', async () => {
    vi.mocked(fetch).mockResolvedValue(
        new Response(JSON.stringify({ preview: { ...PREVIEW, unallocated: '$640,00', unallocatedCents: 64_000, summary: 'Sobran $640,00.' } }), {
            status: 200,
        }),
    );
    render(<ReceiptReview {...props({ preview: { ...PREVIEW, unallocated: '$10,00', unallocatedCents: 1_000 } })} />);

    fireEvent.click(screen.getByLabelText('Confirmo que apruebo con $10,00 sin asignar'));
    expect(screen.getByRole('button', { name: 'Aprobar y aplicar el pago' })).toBeEnabled();

    fireEvent.change(screen.getByLabelText('Importe recibido'), { target: { value: '29000.00' } });
    await settle();

    expect(await screen.findByLabelText('Confirmo que apruebo con $640,00 sin asignar')).not.toBeChecked();
    expect(screen.getByRole('button', { name: 'Aprobar y aplicar el pago' })).toBeDisabled();
});

it('pide la vista previa con la tasa escrita', async () => {
    vi.mocked(fetch).mockResolvedValue(
        new Response(JSON.stringify({ preview: { ...PREVIEW, rateSource: 'manual', summary: 'Con la manual.' } }), { status: 200 }),
    );
    render(<ReceiptReview {...props()} />);

    fireEvent.change(screen.getByLabelText('Tasa (opcional)'), { target: { value: '40' } });
    await settle();

    expect(vi.mocked(fetch).mock.calls[0]?.[0]).toBe('/backoffice/comprobantes/9/vista-previa?amount=2.550%2C00&currency=VES&rate=40');
    expect(await screen.findByText('Con la manual.')).toBeInTheDocument();
});

it('al pasar a dólares olvida la tasa escrita', async () => {
    vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify({ preview: PREVIEW }), { status: 200 }));
    render(<ReceiptReview {...props()} />);

    fireEvent.change(screen.getByLabelText('Tasa (opcional)'), { target: { value: '40' } });
    fireEvent.change(screen.getByLabelText('Moneda'), { target: { value: 'USD' } });
    await settle();

    expect(screen.queryByLabelText('Tasa (opcional)')).not.toBeInTheDocument();
    expect(vi.mocked(fetch).mock.calls.at(-1)?.[0]).toBe('/backoffice/comprobantes/9/vista-previa?amount=2.550%2C00&currency=USD');
});

it('enseña el rechazo del sobrante aunque la vista previa no lo tenga', () => {
    formErrors.current = { confirm_excess: 'El pago excede el saldo del apartado en $5,00.' };
    render(<ReceiptReview {...props()} />);

    expect(screen.getByText('El pago excede el saldo del apartado en $5,00.')).toBeInTheDocument();
});

it('en el celular decide desde la barra fija: Aprobar abre el formulario en una hoja', () => {
    render(<ReceiptReview {...props()} />);

    const bar = screen.getByRole('group', { name: 'Decidir sobre el comprobante' });
    expect(bar).toHaveClass('sticky', 'lg:hidden');
    expect(screen.getByRole('region', { name: 'Decisión' })).toHaveClass('max-lg:hidden');

    fireEvent.click(within(bar).getByRole('button', { name: 'Aprobar' }));

    const sheet = screen.getByRole('dialog', { name: 'Aprobar el comprobante' });
    fireEvent.click(within(sheet).getByRole('button', { name: 'Aprobar y aplicar el pago' }));
    expect(post).toHaveBeenCalledWith('/backoffice/comprobantes/9/aprobar', expect.anything());
});

it('en el celular Rechazar abre el motivo en la hoja', () => {
    render(<ReceiptReview {...props()} />);

    fireEvent.click(within(screen.getByRole('group', { name: 'Decidir sobre el comprobante' })).getByRole('button', { name: 'Rechazar' }));

    const sheet = screen.getByRole('dialog', { name: 'Rechazar el comprobante' });
    expect(within(sheet).getByLabelText('Motivo')).toBeInTheDocument();
    expect(within(sheet).queryByLabelText('Importe recibido')).not.toBeInTheDocument();
});

it('mientras se envía, el botón muestra que está trabajando y no se puede pulsar otra vez', () => {
    processing.current = true;
    render(<ReceiptReview {...props()} />);

    const approve = screen.getByRole('button', { name: 'Aprobando…' });
    expect(approve).toBeDisabled();
    expect(approve).toHaveAttribute('aria-busy', 'true');
});

it('si el importe supera lo que falta, lo dice con la cifra', () => {
    render(<ReceiptReview {...props({ preview: { ...PREVIEW, unallocated: '$10,00', unallocatedCents: 1_000 } })} />);

    expect(screen.getByText('Supera lo que falta: $150,00.')).toBeInTheDocument();
});

it('uno ya revisado lo dice arriba y no ofrece la barra de decisión', () => {
    render(
        <ReceiptReview
            {...props({
                approval: null,
                rejection: null,
                preview: null,
                receipt: { ...props().receipt, status: 'approved', statusLabel: 'Aprobado', reviewedBy: 'Ana Pérez' },
            })}
        />,
    );

    expect(screen.getByText('Este comprobante ya fue revisado')).toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Decidir sobre el comprobante' })).not.toBeInTheDocument();
});
