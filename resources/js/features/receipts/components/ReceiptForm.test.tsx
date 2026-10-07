import { ReceiptForm } from '@/features/receipts/components/ReceiptForm';
import { useReceiptForm } from '@/features/receipts/hooks/useReceiptForm';
import { fakeFile, PAGO_MOVIL, receiptProps, stubObjectUrls, ZELLE } from '@/test/receipt-fixtures';
import { type ReceiptCreateProps } from '@/types/receipt';
import type * as InertiaReact from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

/**
 * Lo que el formulario sugiere sin imponer, y lo que vacía (`REQ-PAY-001`
 * RB-2, RB-9, SP-10), con el hook y el `useForm` de verdad.
 */

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

stubObjectUrls();

beforeEach(() => {
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: { rate: null } }, errors: {} } });
});

/** SP-10 · el método sugiere su moneda, y la moneda se puede cambiar después. */
it('elegir un método sugiere su moneda sin imponerla', () => {
    renderForm({ paymentAccounts: [PAGO_MOVIL, ZELLE] });

    choose('Método de pago', 'efectivo_ves');
    expect(screen.getByLabelText('Moneda')).toHaveValue('VES');
    choose('Método de pago', 'zelle');
    expect(screen.getByLabelText('Moneda')).toHaveValue('USD');

    choose('Método de pago', 'efectivo_ves');
    choose('Moneda', 'USD');
    expect(screen.getByLabelText('Moneda')).toHaveValue('USD');
    expect(screen.getByLabelText('Método de pago')).toHaveValue('efectivo_ves');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('30.00');

    // Elegida a mano, ni otro método ni una cuenta la cambian.
    choose('Método de pago', 'pago_movil');
    choose('Cuenta a la que pagaste', '7');
    expect(screen.getByLabelText('Moneda')).toHaveValue('USD');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('30.00');
});

/** RB-2 · banco y referencia solo con métodos que los piden, y se vacían al pasar a efectivo. */
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

/** RB-9 · la sugerencia sigue a la moneda mientras la persona no escriba la suya. */
it('precarga la sugerencia en la moneda elegida, en dólares y en bolívares', () => {
    renderForm({ paymentAccounts: [] });

    expect(screen.getByLabelText('Moneda')).toHaveValue('USD');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('30.00');

    choose('Moneda', 'VES');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('1275.00');

    choose('Moneda', 'USD');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('30.00');
});

it('no pisa el monto que escribió la persona al cambiar de moneda', () => {
    renderForm({ paymentAccounts: [] });

    choose('Monto que pagaste', '45.50');
    choose('Moneda', 'VES');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('45.50');
});

/** Una cifra escrita no cambia de moneda por elegir otro método: 1275 bolívares no son 1275 dólares. */
it('con un monto escrito, el método ya no cambia la moneda', () => {
    renderForm({ paymentAccounts: [] });

    choose('Método de pago', 'pago_movil');
    expect(screen.getByLabelText('Moneda')).toHaveValue('VES');
    choose('Monto que pagaste', '1275');

    choose('Método de pago', 'zelle');
    expect(screen.getByLabelText('Moneda')).toHaveValue('VES');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('1275');
});

it('sin tasa, en bolívares no precarga la cifra en dólares', () => {
    const base = receiptProps();
    renderForm({ paymentAccounts: [], suggestion: base.suggestion && { ...base.suggestion, amountVes: null } });

    choose('Moneda', 'VES');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('');
});

it('sin sugerencia, el monto arranca vacío y no hay ayuda', () => {
    renderForm({ suggestion: null });

    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('');
    expect(screen.queryByText(/Te sugerimos/)).toBeNull();
});

/** `REQ-PAY-002` RB-5 · el aviso es local y no bloquea: el botón sigue habilitado. */
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
