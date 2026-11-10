import { ManualPaymentForm } from '@/features/receipts/components/ManualPaymentForm';
import { PREVIEW_DEBOUNCE_MS } from '@/features/receipts/hooks/useApprovalPreview';
import { fakeRoute } from '@/test/fakes';
import { MANUAL_PAYMENT_OPTIONS as OPTIONS } from '@/test/manual-payment-fixtures';
import { stubObjectUrls } from '@/test/receipt-fixtures';
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

            return { data, setData, reset: () => setState(defaults), clearErrors: vi.fn(), errors: {}, processing: false, post: send };
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

it('el pago manual se registra desde el pie de la hoja y la cierra al terminar', async () => {
    vi.mocked(fetch).mockImplementation(async () => new Response(JSON.stringify({ preview: PREVIEW }), { status: 200 }));
    const close = vi.fn();
    withQueries(<ManualPaymentForm options={OPTIONS} open onClose={close} title="Registrar pago manual" />);

    fireEvent.change(screen.getByLabelText('Método'), { target: { value: 'efectivo_usd' } });
    fireEvent.change(screen.getByLabelText('Importe'), { target: { value: '30,00' } });
    fireEvent.change(screen.getByLabelText('Nota (opcional)'), { target: { value: 'Recibido en tienda por Ana.' } });
    await act(async () => {
        await vi.advanceTimersByTimeAsync(PREVIEW_DEBOUNCE_MS + 10);
    });

    const dialog = screen.getByRole('dialog', { name: 'Registrar pago manual' });
    const record = screen.getByRole('button', { name: 'Registrar el pago' });
    expect(dialog.querySelector('[data-slot="modal-footer"]')).toContainElement(record);
    expect(dialog.querySelector('[data-slot="modal-body"]')).not.toContainElement(record);

    await vi.waitFor(() => expect(record).toBeEnabled());
    fireEvent.click(record);

    expect(post).toHaveBeenCalledOnce();
    expect(close).toHaveBeenCalledOnce();
});

it('las sugerencias de cuota y saldo rellenan el importe en dólares', () => {
    withQueries(
        <ManualPaymentForm
            options={OPTIONS}
            open
            onClose={() => undefined}
            title="Registrar pago manual"
            suggestions={[
                { key: 'installment-2', label: 'Cuota 2 · $14,50', amount: '14,50' },
                { key: 'balance', label: 'Saldo · $58,00', amount: '58,00' },
            ]}
        />,
    );

    fireEvent.change(screen.getByLabelText('Moneda'), { target: { value: 'VES' } });
    fireEvent.click(screen.getByRole('button', { name: 'Cuota 2 · $14,50' }));

    expect(screen.getByLabelText('Importe')).toHaveValue('14,50');
    expect(screen.getByLabelText('Moneda')).toHaveValue('USD');
    expect(screen.getByRole('button', { name: 'Cuota 2 · $14,50' })).toHaveAttribute('aria-pressed', 'true');

    fireEvent.click(screen.getByRole('button', { name: 'Saldo · $58,00' }));
    expect(screen.getByLabelText('Importe')).toHaveValue('58,00');
});

it('si el importe salió de una sugerencia en dólares y se cambia a bolívares, el importe se vacía en vez de pasar como bolívares', () => {
    withQueries(
        <ManualPaymentForm
            options={OPTIONS}
            open
            onClose={() => undefined}
            title="Registrar pago manual"
            suggestions={[{ key: 'installment-1', label: 'Cuota 1 · $5,25', amount: '5,25' }]}
        />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Cuota 1 · $5,25' }));
    fireEvent.change(screen.getByLabelText('Moneda'), { target: { value: 'VES' } });

    expect(screen.getByLabelText('Moneda')).toHaveValue('VES');
    expect(screen.getByLabelText('Importe')).toHaveValue('');
});

it('un importe escrito a mano se conserva al cambiar la moneda', () => {
    withQueries(
        <ManualPaymentForm
            options={OPTIONS}
            open
            onClose={() => undefined}
            title="Registrar pago manual"
            suggestions={[{ key: 'installment-1', label: 'Cuota 1 · $5,25', amount: '5,25' }]}
        />,
    );

    fireEvent.change(screen.getByLabelText('Importe'), { target: { value: '4.700,00' } });
    fireEvent.change(screen.getByLabelText('Moneda'), { target: { value: 'VES' } });

    expect(screen.getByLabelText('Importe')).toHaveValue('4.700,00');
});

it('deja registrar el pago sin nota', async () => {
    vi.mocked(fetch).mockImplementation(async () => new Response(JSON.stringify({ preview: PREVIEW }), { status: 200 }));
    withQueries(<ManualPaymentForm options={OPTIONS} open onClose={() => undefined} title="Registrar pago manual" />);

    fireEvent.change(screen.getByLabelText('Método'), { target: { value: 'efectivo_usd' } });
    fireEvent.change(screen.getByLabelText('Importe'), { target: { value: '30,00' } });
    await act(async () => {
        await vi.advanceTimersByTimeAsync(PREVIEW_DEBOUNCE_MS + 10);
    });

    const record = screen.getByRole('button', { name: 'Registrar el pago' });
    expect(screen.getByLabelText('Nota (opcional)')).toHaveValue('');
    await vi.waitFor(() => expect(record).toBeEnabled());
});
