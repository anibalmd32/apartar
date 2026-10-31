import { AppRoot } from '@/app-root';
import { ManualPaymentForm } from '@/features/receipts/components/ManualPaymentForm';
import { MANUAL_PAYMENT_OPTIONS as OPTIONS } from '@/test/manual-payment-fixtures';
import { fakeFile, stubObjectUrls } from '@/test/receipt-fixtures';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        usePage,
        Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = useState(initial);
            const setData = (key: keyof T | ((previous: T) => T), value?: unknown) =>
                setState((previous) => (typeof key === 'function' ? key(previous) : { ...previous, [key]: value }));

            return { data, setData, reset: vi.fn(), errors: {}, processing: false, post: vi.fn() };
        },
    };
});

stubObjectUrls();

const READ_URL = OPTIONS.readUrl;

function answer(reading: Record<string, string | null>) {
    return vi.fn(async (url: string) =>
        url === READ_URL
            ? new Response(JSON.stringify({ reading }), { status: 200 })
            : new Response(JSON.stringify({ message: 'Escribe el importe.' }), { status: 422 }),
    );
}

beforeEach(() => {
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', rate: { rate: null } }, errors: {} } });
});

afterEach(() => {
    vi.unstubAllGlobals();
});

function renderForm() {
    return render(
        <AppRoot>
            <ManualPaymentForm options={OPTIONS} />
        </AppRoot>,
    );
}

it('al adjuntar la captura la valida, bloquea los datos y rellena lo que leyó', async () => {
    const fetch = answer({ amount: '1275.00', currency: 'VES', paidAt: '2026-09-11', reference: '00123456', bank: 'Mercantil' });
    vi.stubGlobal('fetch', fetch);
    renderForm();

    fireEvent.change(screen.getByLabelText('Método'), { target: { value: 'pago_movil' } });
    fireEvent.change(screen.getByLabelText('Comprobante (opcional)'), { target: { files: [fakeFile('captura.png', 'image/png')] } });

    expect(screen.getByRole('status')).toHaveTextContent('Estamos validando el comprobante');
    expect(screen.getByLabelText('Importe')).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Registrar el pago' })).toBeDisabled();

    await waitFor(() => expect(screen.getByLabelText('Número de referencia')).toHaveValue('00123456'));
    expect(screen.getByLabelText('Importe')).toHaveValue('1275.00');
    expect(screen.getByLabelText('Moneda')).toHaveValue('VES');
    expect(fetch).toHaveBeenCalledWith(READ_URL, expect.objectContaining({ method: 'POST' }));
    expect(screen.queryByText(/\bIA\b|inteligencia artificial/)).toBeNull();
});

it('lo que no se leyó queda vacío y un PDF no se manda a validar', async () => {
    const fetch = answer({ amount: null, currency: null, paidAt: null, reference: null, bank: null });
    vi.stubGlobal('fetch', fetch);
    renderForm();

    fireEvent.change(screen.getByLabelText('Importe'), { target: { value: '10.00' } });
    fireEvent.change(screen.getByLabelText('Comprobante (opcional)'), { target: { files: [fakeFile('captura.jpg', 'image/jpeg')] } });

    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('completa lo que esté vacío'));
    expect(screen.getByLabelText('Importe')).toHaveValue('');

    fetch.mockClear();
    fireEvent.change(screen.getByLabelText('Comprobante (opcional)'), { target: { files: [fakeFile('pago.pdf', 'application/pdf')] } });

    expect(fetch).not.toHaveBeenCalledWith(READ_URL, expect.anything());
});
