import ReceiptReview from '@/pages/backoffice/receipts/Review';
import { PREVIEW, reviewProps as props } from '@/test/receipt-review-fixtures';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, within } from '@testing-library/react';
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

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
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

it('al pasar al siguiente comprobante cierra la hoja y toma el importe y la moneda del nuevo', () => {
    const { rerender } = render(<ReceiptReview {...props()} />);

    fireEvent.click(within(screen.getByRole('group', { name: 'Decidir sobre el comprobante' })).getByRole('button', { name: 'Aprobar' }));
    const sheet = screen.getByRole('dialog', { name: 'Aprobar el comprobante' });
    fireEvent.change(within(sheet).getByLabelText('Importe recibido'), { target: { value: '15,00' } });
    fireEvent.change(within(sheet).getByLabelText('Moneda'), { target: { value: 'USD' } });

    const next = { ...props().receipt, id: 13, declaredAmount: '311.20', declaredCurrency: 'VES' as const, declared: 'Bs. 311,20' };
    rerender(<ReceiptReview {...props({ receipt: next })} />);

    expect(screen.queryByRole('dialog', { name: 'Aprobar el comprobante' })).not.toBeInTheDocument();
    const panel = screen.getByRole('region', { name: 'Decisión' });
    expect(within(panel).getByLabelText('Importe recibido')).toHaveValue('311,20');
    expect(within(panel).getByLabelText('Moneda')).toHaveValue('VES');
});

it('las pestañas del panel miden 44px en pantallas táctiles', () => {
    render(<ReceiptReview {...props()} />);

    const tabs = within(screen.getByRole('group', { name: 'Qué hacer con el comprobante' })).getAllByRole('button');
    tabs.forEach((tab) => expect(tab).toHaveClass('touch:h-11'));
});
