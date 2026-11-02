import { ReceiptForm } from '@/features/receipts/components/ReceiptForm';
import { useReceiptForm } from '@/features/receipts/hooks/useReceiptForm';
import { fakeFile, receiptProps, stubObjectUrls } from '@/test/receipt-fixtures';
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
    renderForm({ paymentAccounts: [] });

    choose('Método de pago', 'efectivo_ves');
    expect(checkedIn('Moneda')).toBe('VES');
    choose('Método de pago', 'zelle');
    expect(checkedIn('Moneda')).toBe('USD');

    choose('Método de pago', 'efectivo_ves');
    pick('Moneda', 'USD');
    expect(checkedIn('Moneda')).toBe('USD');
    expect(screen.getByLabelText('Método de pago')).toHaveValue('efectivo_ves');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('30,00');
});

it('con una cuenta elegida no vuelve a preguntar el método', () => {
    renderForm();

    expect(screen.queryByLabelText('Método de pago')).toBeNull();
    expect(screen.queryByRole('group', { name: 'Cuenta a la que pagaste' })).toBeNull();
});

it('pide banco y referencia solo cuando el método los lleva, y los vacía al pasar a efectivo', () => {
    renderForm({ paymentAccounts: [] });

    choose('Método de pago', 'pago_movil');

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

it('con cuotas vencidas propone ponerse al día con el total', () => {
    const base = receiptProps();
    const suggestion = base.suggestion && {
        ...base.suggestion,
        kind: 'overdue' as const,
        installmentNumber: null,
        overdueInstallments: 2,
        amount: { ...base.suggestion.amount, usd: '$60,00' },
        amountUsd: '60.00',
    };
    renderForm({ paymentAccounts: [], suggestion });

    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('60,00');
    expect(screen.getByText('Ya pusimos el monto que te toca. Si pagaste otro monto, escríbelo.')).toBeInTheDocument();
});

it('la moneda va en su fila, antes del monto, y el monto muestra el símbolo de la moneda elegida', () => {
    renderForm({ paymentAccounts: [] });

    const amount = screen.getByLabelText('Monto que pagaste');
    const currency = screen.getByRole('group', { name: 'Moneda' });
    const amountField = amount.closest('fieldset');

    expect(amountField?.contains(currency)).toBe(false);
    expect(currency.parentElement).toBe(amountField?.parentElement);
    expect(currency.compareDocumentPosition(amount) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(amount.closest('label')).toHaveTextContent('$');

    pick('Moneda', 'VES');
    expect(screen.getByLabelText('Monto que pagaste').closest('label')).toHaveTextContent('Bs.');
});

it('no pisa el monto que escribió la persona al cambiar de moneda', () => {
    renderForm({ paymentAccounts: [] });

    choose('Monto que pagaste', '45,50');
    pick('Moneda', 'VES');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('45,50');
});

it('con un monto escrito, el método ya no cambia la moneda', () => {
    renderForm({ paymentAccounts: [] });

    choose('Método de pago', 'pago_movil');
    expect(checkedIn('Moneda')).toBe('VES');
    choose('Monto que pagaste', '1275');

    choose('Método de pago', 'zelle');
    expect(checkedIn('Moneda')).toBe('VES');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('1.275');
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
    expect(screen.queryByText(/Ya pusimos el monto/)).toBeNull();
});

it('avisa si el archivo pasa del límite, sin bloquear el envío', () => {
    renderForm();

    fireEvent.change(screen.getByLabelText('Captura o PDF del pago'), { target: { files: [fakeFile('foto.jpg', 'image/jpeg', 6 * 1024 * 1024)] } });

    expect(screen.getByRole('alert')).toHaveTextContent('Este archivo pasa de 5 MB');
    expect(screen.getByRole('button', { name: 'Enviar comprobante' })).toBeEnabled();

    fireEvent.change(screen.getByLabelText('Captura o PDF del pago'), { target: { files: [fakeFile('foto.jpg', 'image/jpeg', 4 * 1024 * 1024)] } });
    expect(screen.queryByText(/pasa de 5 MB/)).toBeNull();
    expect(screen.getByRole('group', { name: 'Archivo elegido' })).toHaveTextContent('foto.jpg');
});

it('el selector acepta solo los tipos del servidor', () => {
    renderForm();

    expect(screen.getByLabelText('Captura o PDF del pago')).toHaveAttribute('accept', '.jpg,.jpeg,.png,.webp,.pdf');
});
