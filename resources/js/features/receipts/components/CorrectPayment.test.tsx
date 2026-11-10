import { PaymentHistory } from '@/features/receipts/components/PaymentHistory';
import { PREVIEW_DEBOUNCE_MS } from '@/features/receipts/hooks/useApprovalPreview';
import { centsUsd } from '@/lib/money';
import { fakeRoute } from '@/test/fakes';
import { MANUAL_PAYMENT_OPTIONS as OPTIONS } from '@/test/manual-payment-fixtures';
import { stubObjectUrls } from '@/test/receipt-fixtures';
import { PREVIEW } from '@/test/receipt-review-fixtures';
import { type PaymentRow } from '@/types/receipt';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { post, usePage } = vi.hoisted(() => ({ post: vi.fn(), usePage: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        usePage,
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = useState(initial);
            const setData = (key: keyof T | ((previous: T) => T), value?: unknown) =>
                setState((previous) => (typeof key === 'function' ? key(previous) : { ...previous, [key]: value }));

            return { data, setData, reset: vi.fn(), clearErrors: vi.fn(), errors: {}, processing: false, post: (url: string) => post(url, data) };
        },
    };
});

const PAYMENT: PaymentRow = {
    settledAt: '12 sept 2026',
    amountUsd: { cents: centsUsd(6_000), usd: '$60,00', ves: null },
    declaredAmount: '$60,00',
    rateApplied: null,
    rateSource: 'bcv',
    rateSourceLabel: 'BCV',
    method: 'Efectivo en dólares',
    appliedTo: [1, 2],
    downPayment: null,
    unallocated: null,
    registeredByBusiness: true,
    note: 'Pago recibido en tienda.',
    bankReference: null,
    attachmentUrl: null,
    fromReceipt: false,
    voided: null,
    voidUrl: '/backoffice/pagos/7/anular',
    correction: {
        storeUrl: '/backoffice/pagos/7/corregir',
        previewUrl: '/backoffice/pagos/7/correccion/vista-previa',
        targetUrl: '/backoffice/pagos/7/destino',
        values: {
            method: 'efectivo_usd',
            payment_account: '',
            amount: '60,00',
            currency: 'USD',
            rate: '',
            paid_at: '2026-09-12',
            bank: '',
            reference: '',
        },
    },
};

stubObjectUrls();

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.stubGlobal('route', fakeRoute);
    post.mockReset();
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', rate: { rate: null } }, errors: {} } });
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

function openMenu(fields: typeof OPTIONS | null) {
    render(
        <QueryClientProvider client={new QueryClient()}>
            <PaymentHistory payments={[PAYMENT]} empty="—" correctionFields={fields} />
        </QueryClientProvider>,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Acciones del pago de $60,00' }));
}

it('en un apartado que ya no recibe pagos ofrece mover y anular, pero no editar', () => {
    openMenu(null);

    expect(screen.queryByRole('menuitem', { name: 'Editar pago' })).toBeNull();
    expect(screen.getByRole('menuitem', { name: 'Mover a otro apartado' })).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: 'Anular pago' })).toBeInTheDocument();
});

it('editar abre el formulario con los datos del pago y guarda en la ruta de corrección', async () => {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => new Response(JSON.stringify({ preview: PREVIEW }), { status: 200 })),
    );
    openMenu(OPTIONS);
    fireEvent.click(screen.getByRole('menuitem', { name: 'Editar pago' }));

    expect(screen.getByLabelText('Importe')).toHaveValue('60,00');
    fireEvent.change(screen.getByLabelText('Importe'), { target: { value: '30,00' } });
    fireEvent.change(screen.getByLabelText('Motivo (obligatorio)'), { target: { value: 'Era de 30, no de 60.' } });
    await act(async () => {
        await vi.advanceTimersByTimeAsync(PREVIEW_DEBOUNCE_MS + 10);
    });

    expect(vi.mocked(fetch).mock.calls[0]?.[0]).toContain('/backoffice/pagos/7/correccion/vista-previa');
    const save = screen.getByRole('button', { name: 'Guardar la corrección' });
    await vi.waitFor(() => expect(save).toBeEnabled());
    fireEvent.click(save);

    expect(post).toHaveBeenCalledWith('/backoffice/pagos/7/corregir', expect.objectContaining({ amount: '30,00', method: 'efectivo_usd' }));
});

it('mover busca el apartado por código y registra allá con los mismos datos', async () => {
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string) =>
            url.includes('/destino')
                ? new Response(
                      JSON.stringify({
                          layaway: { code: 'AP-K7M2Q9RT', customer: 'Irma Díaz', product: 'Bolso tote', remaining: '$90,00' },
                          options: { ...OPTIONS, storeUrl: '/backoffice/pagos/7/corregir?destino=AP-K7M2Q9RT' },
                      }),
                      { status: 200 },
                  )
                : new Response(JSON.stringify({ preview: PREVIEW }), { status: 200 }),
        ),
    );
    openMenu(OPTIONS);
    fireEvent.click(screen.getByRole('menuitem', { name: 'Mover a otro apartado' }));
    fireEvent.change(screen.getByLabelText('Código del apartado'), { target: { value: 'ap-k7m2q9rt' } });
    fireEvent.click(screen.getByRole('button', { name: 'Buscar apartado' }));

    expect(await screen.findByRole('dialog', { name: 'Mover a AP-K7M2Q9RT' })).toHaveTextContent('Irma Díaz · Bolso tote · faltan $90,00');
    expect(vi.mocked(fetch).mock.calls[0]?.[0]).toBe('/backoffice/pagos/7/destino?codigo=ap-k7m2q9rt');
    expect(screen.getByLabelText('Importe')).toHaveValue('60,00');
});
