import { ReceiptForm } from '@/features/receipts/components/ReceiptForm';
import { useReceiptForm } from '@/features/receipts/hooks/useReceiptForm';
import { fakeFile, PAGO_MOVIL, receiptProps, stubObjectUrls, ZELLE } from '@/test/receipt-fixtures';
import { type ReceiptCreateProps } from '@/types/receipt';
import type * as InertiaReact from '@inertiajs/react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', async (importOriginal) => ({ ...(await importOriginal<typeof InertiaReact>()), usePage }));

function Harness(props: ReceiptCreateProps) {
    const form = useReceiptForm(props);

    return <ReceiptForm form={form} {...props} businessName={props.business.name} />;
}

function renderForm(overrides: Partial<ReceiptCreateProps> = {}) {
    return render(<Harness {...receiptProps(overrides)} />);
}

const choose = (label: string, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } });

const checkedIn = (group: string) =>
    within(screen.getByRole('group', { name: group }))
        .getAllByRole<HTMLInputElement>('radio')
        .find((radio) => radio.checked)?.value ?? '';

const pick = (group: string, value: string) => {
    const radio = within(screen.getByRole('group', { name: group }))
        .getAllByRole<HTMLInputElement>('radio')
        .find((candidate) => candidate.value === value);
    if (radio === undefined) throw new Error(`No hay «${value}» en «${group}»`);
    fireEvent.click(radio);
};

stubObjectUrls();

beforeEach(() => {
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: { rate: null } }, errors: {} } });
});

it('elegir un método sugiere su moneda sin imponerla', () => {
    renderForm({ paymentAccounts: [PAGO_MOVIL, ZELLE] });

    choose('Método de pago', 'efectivo_ves');
    expect(checkedIn('Moneda')).toBe('VES');
    choose('Método de pago', 'zelle');
    expect(checkedIn('Moneda')).toBe('USD');

    choose('Método de pago', 'efectivo_ves');
    pick('Moneda', 'USD');
    expect(checkedIn('Moneda')).toBe('USD');
    expect(screen.getByLabelText('Método de pago')).toHaveValue('efectivo_ves');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('30,00');

    choose('Método de pago', 'pago_movil');
    pick('Cuenta a la que pagaste', '7');
    expect(checkedIn('Moneda')).toBe('USD');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('30,00');
});

it('pide banco y referencia solo cuando el método los lleva, y los vacía al pasar a efectivo', () => {
    renderForm();

    choose('Banco', 'Mercantil');
    choose('Número de referencia', '00123456');

    choose('Método de pago', 'efectivo_usd');
    expect(screen.queryByLabelText('Banco')).toBeNull();
    expect(screen.queryByLabelText('Número de referencia')).toBeNull();

    choose('Método de pago', 'pago_movil');
    expect(screen.getByLabelText('Banco')).toHaveValue('');
    expect(screen.getByLabelText('Número de referencia')).toHaveValue('');
});

it('sin método elegido no pide banco ni referencia', () => {
    renderForm({ paymentAccounts: [] });

    expect(screen.getByLabelText('Método de pago')).toHaveValue('');
    expect(screen.queryByLabelText('Banco')).toBeNull();
});

it('precarga la sugerencia en la moneda elegida, en dólares y en bolívares', () => {
    renderForm({ paymentAccounts: [] });

    expect(checkedIn('Moneda')).toBe('USD');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('30,00');

    pick('Moneda', 'VES');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('1.275,00');

    pick('Moneda', 'USD');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('30,00');
});

it('no pisa el monto que escribió la persona al cambiar de moneda', () => {
    renderForm({ paymentAccounts: [] });

    choose('Monto que pagaste', '45.50');
    pick('Moneda', 'VES');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('45.50');
});

it('con un monto escrito, el método ya no cambia la moneda', () => {
    renderForm({ paymentAccounts: [] });

    choose('Método de pago', 'pago_movil');
    expect(checkedIn('Moneda')).toBe('VES');
    choose('Monto que pagaste', '1275');

    choose('Método de pago', 'zelle');
    expect(checkedIn('Moneda')).toBe('VES');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('1275');
});

it('sin tasa, en bolívares no precarga la cifra en dólares', () => {
    const base = receiptProps();
    renderForm({ paymentAccounts: [], suggestion: base.suggestion && { ...base.suggestion, amountVes: null } });

    pick('Moneda', 'VES');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('');
});

it('sin sugerencia, el monto arranca vacío y no hay ayuda', () => {
    renderForm({ suggestion: null });

    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('');
    expect(screen.queryByText(/Te sugerimos/)).toBeNull();
});

it('avisa si el archivo pasa del límite, sin bloquear el envío', () => {
    renderForm();

    fireEvent.change(screen.getByLabelText('Captura o PDF del pago'), { target: { files: [fakeFile('foto.jpg', 'image/jpeg', 6 * 1024 * 1024)] } });

    expect(screen.getByRole('alert')).toHaveTextContent('Este archivo pasa de 5 MB');
    expect(screen.getByRole('button', { name: 'Enviar comprobante' })).toBeEnabled();

    fireEvent.change(screen.getByLabelText('Captura o PDF del pago'), { target: { files: [fakeFile('foto.jpg', 'image/jpeg', 4 * 1024 * 1024)] } });
    expect(screen.queryByText(/pasa de 5 MB/)).toBeNull();
    expect(screen.getByText('JPG, PNG, WebP o PDF, hasta 5 MB.')).toBeInTheDocument();
});

it('el selector acepta solo los tipos del servidor', () => {
    renderForm();

    expect(screen.getByLabelText('Captura o PDF del pago')).toHaveAttribute('accept', '.jpg,.jpeg,.png,.webp,.pdf');
});
