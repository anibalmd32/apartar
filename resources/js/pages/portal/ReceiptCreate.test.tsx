import ReceiptCreate from '@/pages/portal/ReceiptCreate';
import { fakeRoute } from '@/test/fakes';
import { fakeFile, PAGO_MOVIL, receiptProps, stubObjectUrls, ZELLE } from '@/test/receipt-fixtures';
import { type ReceiptCreateProps } from '@/types/receipt';
import { router } from '@inertiajs/core';
import type * as InertiaReact from '@inertiajs/react';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof InertiaReact>()),
    usePage,
    Head: () => null,
    Link: (await import('@/test/fakes')).FakeLink,
}));

interface VisitOptions {
    method: string;
    data: Record<string, unknown>;
    forceFormData?: boolean;
    preserveScroll?: boolean;
    onStart?: (visit: unknown) => void;
    onError?: (errors: Record<string, string>) => void;
}

let visit: ReturnType<typeof vi.spyOn>;

stubObjectUrls();

function renderPage(overrides: Partial<ReceiptCreateProps> = {}) {
    return render(<ReceiptCreate {...receiptProps(overrides)} />);
}

function lastVisit(): [string, VisitOptions] {
    return visit.mock.calls.at(-1) as [string, VisitOptions];
}

beforeEach(() => {
    usePage.mockReturnValue({
        props: {
            auth: { portal: { fullName: 'María González' } },
            currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: { rate: null } },
            errors: {},
        },
    });
    vi.stubGlobal('route', fakeRoute);
    visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

it('dice de qué apartado es, cuánto falta y enlaza de vuelta', () => {
    renderPage();

    const summary = screen.getByRole('region', { name: 'Apartado' });
    expect(summary).toHaveTextContent('Nevera Mabe');
    expect(summary).toHaveTextContent('AP-7K3M9QRT');
    expect(summary).toHaveTextContent('Al día');
    expect(summary).toHaveTextContent('Te falta: $150,00');
    expect(screen.getByRole('link', { name: '← Volver al apartado' })).toHaveAttribute('href', '/mi-cuenta/apartados/AP-7K3M9QRT');
    expect(screen.getByText('María González')).toBeInTheDocument();
});

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

it('preselecciona la única cuenta, su método y la sugerencia en su moneda', () => {
    renderPage();

    expect(checkedIn('Cuenta a la que pagaste')).toBe('7');
    expect(screen.getByRole('radio', { name: 'Pago móvil · Banesco · 0414-1234567' })).toBeChecked();
    expect(screen.getByLabelText('Método de pago')).toHaveValue('pago_movil');
    expect(checkedIn('Moneda')).toBe('VES');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('1275.00');
    expect(screen.getByText('J-12345678-9', { exact: false })).toBeInTheDocument();
});

it('con varias cuentas no elige ninguna, y elegir una pone su método', () => {
    renderPage({ paymentAccounts: [PAGO_MOVIL, ZELLE] });

    expect(checkedIn('Cuenta a la que pagaste')).toBe('');
    expect(screen.getByLabelText('Método de pago')).toHaveValue('');

    pick('Cuenta a la que pagaste', '9');

    expect(screen.getByLabelText('Método de pago')).toHaveValue('zelle');
    expect(checkedIn('Moneda')).toBe('USD');
    expect(screen.getAllByText('pagos@oriente.com')).toHaveLength(2);
});

it('sin cuentas de cobro, quita el campo y avisa', () => {
    renderPage({ paymentAccounts: [] });

    expect(screen.queryByRole('group', { name: 'Cuenta a la que pagaste' })).toBeNull();
    expect(screen.getByRole('alert')).toHaveTextContent('Este negocio no tiene cuentas de cobro publicadas: indica en la nota a dónde pagaste.');
});

it('acota la fecha del pago y arranca en hoy', () => {
    renderPage();

    const date = screen.getByLabelText('Fecha del pago');
    expect(date).toHaveTextContent('05/10/2026');
    expect(document.querySelector('input[name="paidAt"]')).toHaveValue('2026-10-05');

    fireEvent.click(date);

    expect(screen.getByRole('gridcell', { name: / 5 de octubre de 2026/ })).toBeEnabled();
    expect(screen.getByRole('gridcell', { name: / 6 de octubre de 2026/ })).toBeDisabled();
});

it('enseña el monto sugerido como ayuda', () => {
    renderPage();

    expect(screen.getByText(/Te sugerimos la cuota 2:/)).toHaveTextContent('Te sugerimos la cuota 2: $30,00. Si pagaste otro monto, escríbelo.');
});

it('envía multipart con los nombres del contrato y el archivo', () => {
    renderPage();

    const capture = fakeFile('captura.png', 'image/png');
    const bank = screen.getByLabelText('Banco');
    fireEvent.change(bank, { target: { value: 'Mercantil' } });
    fireEvent.keyDown(bank, { key: 'ArrowDown' });
    fireEvent.keyDown(bank, { key: 'Enter' });
    fireEvent.change(screen.getByLabelText('Número de referencia'), { target: { value: '00123456' } });
    fireEvent.change(screen.getByLabelText('Nota (opcional)'), { target: { value: 'Cuota de octubre' } });
    fireEvent.change(screen.getByLabelText('Captura o PDF del pago'), { target: { files: [capture] } });

    fireEvent.click(screen.getByRole('button', { name: 'Enviar comprobante' }));

    const [url, options] = lastVisit();
    expect(url).toBe('/mi-cuenta/apartados/AP-7K3M9QRT/comprobante');
    expect(options.method).toBe('post');
    expect(options.forceFormData).toBe(true);
    expect(options.preserveScroll).toBe(true);
    expect(options.data).toEqual({
        paymentAccount: 7,
        method: 'pago_movil',
        bank: 'Mercantil',
        reference: '00123456',
        paidAt: '2026-10-05',
        amount: '1275.00',
        currency: 'VES',
        note: 'Cuota de octubre',
        file: capture,
    });
});

it('sin cuentas, envía la cuenta como null', () => {
    renderPage({ paymentAccounts: [] });

    fireEvent.change(screen.getByLabelText('Método de pago'), { target: { value: 'efectivo_usd' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enviar comprobante' }));

    expect(lastVisit()[1].data).toMatchObject({
        paymentAccount: null,
        method: 'efectivo_usd',
        bank: '',
        reference: '',
        currency: 'USD',
        amount: '30.00',
    });
});

it('pinta los errores del servidor junto a su campo', () => {
    renderPage();

    fireEvent.click(screen.getByRole('button', { name: 'Enviar comprobante' }));
    const options = lastVisit()[1];

    act(() => options.onStart?.({}));
    expect(screen.getByRole('button', { name: 'Enviando…' })).toBeDisabled();

    act(() =>
        options.onError?.({
            file: 'Este comprobante ya fue reportado.',
            reference: 'La referencia es obligatoria.',
            paidAt: 'La fecha no puede ser futura.',
            amount: 'El monto debe ser mayor que cero.',
        }),
    );

    const field = (label: string) => screen.getByLabelText(label).closest('fieldset') as HTMLElement;
    expect(within(field('Captura o PDF del pago')).getByRole('alert')).toHaveTextContent('Este comprobante ya fue reportado.');
    expect(within(field('Número de referencia')).getByRole('alert')).toHaveTextContent('La referencia es obligatoria.');
    expect(within(field('Fecha del pago')).getByRole('alert')).toHaveTextContent('La fecha no puede ser futura.');
    expect(within(field('Monto que pagaste')).getByRole('alert')).toHaveTextContent('El monto debe ser mayor que cero.');
    expect(screen.getByLabelText('Captura o PDF del pago')).toHaveAttribute('aria-invalid', 'true');
});
