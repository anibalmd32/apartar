import { PaymentHistory } from '@/features/receipts/components/PaymentHistory';
import { centsUsd } from '@/lib/money';
import { type PaymentRow } from '@/types/receipt';
import { type SafeguardOptions } from '@/types/safeguard';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

const { post, setData } = vi.hoisted(() => ({ post: vi.fn(), setData: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { delete: vi.fn() },
    useForm: (initial: Record<string, unknown>) => ({ data: initial, setData, post, processing: false, errors: {} }),
}));

const OPTIONS: SafeguardOptions = {
    destinations: [
        { value: 'binance_usdt', label: 'Binance (USDT)' },
        { value: 'zelle', label: 'Zelle' },
    ],
    file: { accept: '.jpg,.png,.pdf', maxMb: 5 },
};

const PAYMENT: PaymentRow = {
    settledAt: '3 nov 2026',
    amountUsd: { cents: centsUsd(10_000), usd: '$100,00', ves: null },
    declaredAmount: 'Bs. 89.000,00',
    rateApplied: '890.00000000',
    rateSource: 'bcv',
    rateSourceLabel: 'BCV',
    method: 'Pago móvil',
    appliedTo: [1],
    downPayment: null,
    unallocated: null,
    registeredByBusiness: false,
    note: null,
    bankReference: null,
    attachmentUrl: null,
    safeguard: {
        equivalent: '$89,00',
        gap: '-$11,00',
        parallelRate: '1000.00000000',
        storeUrl: '/backoffice/pagos/4/resguardo',
        destroyUrl: null,
        record: null,
    },
};

it('cada pago dice cuánto vale en paralelo y abre el formulario del resguardo', () => {
    render(<PaymentHistory payments={[PAYMENT]} empty="—" safeguardOptions={OPTIONS} />);

    const line = screen.getByLabelText('Resguardo del pago');
    expect(line).toHaveTextContent('Vale $89,00 en paralelo (paralelo 1.000,00) · diferencia -$11,00');
    expect(line).toHaveTextContent('Todavía no indicas dónde quedó este dinero.');

    fireEvent.click(within(line).getByRole('button', { name: 'Registrar resguardo' }));

    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText('Registrar el resguardo')).toBeInTheDocument();
    expect(within(dialog).getByRole('option', { name: 'Binance (USDT)' })).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: 'Guardar' })).toBeDisabled();
});

it('un resguardo registrado muestra dónde quedó, su referencia y su comprobante', () => {
    const recorded: PaymentRow = {
        ...PAYMENT,
        safeguard: {
            ...PAYMENT.safeguard!,
            destroyUrl: '/backoffice/pagos/4/resguardo',
            record: {
                destination: 'binance_usdt',
                destinationLabel: 'Binance (USDT)',
                reference: 'ORD-77',
                note: 'Comprado en P2P',
                proofUrl: '/backoffice/pagos/4/resguardo/archivo?signature=x',
                recordedAt: '3 nov 2026',
            },
        },
    };

    render(<PaymentHistory payments={[recorded]} empty="—" safeguardOptions={OPTIONS} />);

    const line = screen.getByLabelText('Resguardo del pago');
    expect(line).toHaveTextContent('Resguardado en Binance (USDT)');
    expect(line).toHaveTextContent('ref. ORD-77');
    expect(within(line).getByRole('link', { name: 'Ver comprobante del resguardo' })).toHaveAttribute(
        'href',
        '/backoffice/pagos/4/resguardo/archivo?signature=x',
    );
    expect(within(line).getByRole('button', { name: 'Editar resguardo' })).toBeInTheDocument();
});

it('sin resguardo activo, o en el portal, no aparece', () => {
    render(<PaymentHistory payments={[PAYMENT]} empty="—" />);

    expect(screen.queryByLabelText('Resguardo del pago')).toBeNull();
});
