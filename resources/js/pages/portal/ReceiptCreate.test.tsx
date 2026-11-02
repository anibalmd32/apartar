import ReceiptCreate from '@/pages/portal/ReceiptCreate';
import { fakeRoute } from '@/test/fakes';
import { fakeFile, PAGO_MOVIL, receiptProps, stubObjectUrls, ZELLE } from '@/test/receipt-fixtures';
import { type ReceiptCreateProps } from '@/types/receipt';
import { router } from '@inertiajs/core';
import type * as InertiaReact from '@inertiajs/react';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
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
    expect(summary).toHaveTextContent('Te falta $150,00');
    expect(screen.getByRole('link', { name: 'Volver al apartado' })).toHaveAttribute('href', '/mi-cuenta/apartados/AP-7K3M9QRT');
    expect(screen.getByText('María González')).toBeInTheDocument();
    expect(summary.querySelector('img')).toBeNull();
});

const startUpload = () => fireEvent.click(screen.getByRole('button', { name: 'Ya pagué, subir el comprobante' }));

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

it('preselecciona la única cuenta sin volver a preguntar el método, y la sugerencia en su moneda', () => {
    renderPage();
    startUpload();

    expect(screen.queryByRole('group', { name: '¿A cuál cuenta vas a pagar?' })).toBeNull();
    expect(screen.queryByLabelText('Método de pago')).toBeNull();
    expect(screen.getByLabelText('Número de referencia')).toHaveAttribute('inputmode', 'numeric');
    expect(checkedIn('Moneda')).toBe('VES');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('1.275,00');
    expect(screen.getByText('J-12345678-9', { exact: false })).toBeInTheDocument();
});

it('con varias cuentas no elige ninguna, y elegir una pone su método', () => {
    renderPage({ paymentAccounts: [PAGO_MOVIL, ZELLE] });

    expect(checkedIn('¿A cuál cuenta vas a pagar?')).toBe('');
    startUpload();
    expect(screen.getByRole('group', { name: '¿A cuál cuenta vas a pagar?' })).toHaveTextContent('Elige la cuenta a la que vas a pagar.');
    expect(screen.queryByLabelText('Captura o PDF del pago')).toBeNull();

    pick('¿A cuál cuenta vas a pagar?', '9');
    startUpload();

    expect(screen.queryByLabelText('Método de pago')).toBeNull();
    expect(screen.getByLabelText('Número de referencia')).toHaveAttribute('inputmode', 'text');
    expect(checkedIn('Moneda')).toBe('USD');
    expect(screen.getAllByText('pagos@oriente.com')).toHaveLength(2);
});

it('sin cuentas de cobro, quita el campo y avisa', () => {
    renderPage({ paymentAccounts: [] });

    expect(screen.queryByRole('group', { name: '¿A cuál cuenta vas a pagar?' })).toBeNull();
    expect(screen.getByRole('alert')).toHaveTextContent('Ferretería Oriente todavía no publicó sus cuentas de cobro.');
    startUpload();
    expect(screen.getByLabelText('Método de pago')).toHaveValue('');
});

it('acota la fecha del pago y arranca en hoy', () => {
    renderPage();
    startUpload();

    const date = screen.getByLabelText('Fecha del pago');
    expect(date).toHaveTextContent('05/10/2026');
    expect(document.querySelector('input[name="paidAt"]')).toHaveValue('2026-10-05');

    fireEvent.click(date);

    expect(screen.getByRole('gridcell', { name: / 5 de octubre de 2026/ })).toBeEnabled();
    expect(screen.getByRole('gridcell', { name: / 6 de octubre de 2026/ })).toBeDisabled();
});

it('el monto sugerido se precarga sin repetir la cuota ni la cifra', () => {
    renderPage();
    startUpload();

    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('1.275,00');
    expect(screen.getByText('Ya pusimos el monto que te toca. Si pagaste otro monto, escríbelo.')).toBeInTheDocument();
    expect(screen.queryByText(/La cuota 2/)).toBeNull();
    expect(screen.queryByText(/Te sugerimos/)).toBeNull();
});

it('con parte de la inicial abonada, dice que toca lo que falta de la inicial', () => {
    const suggestion = receiptProps().suggestion!;
    renderPage({ suggestion: { ...suggestion, kind: 'down_payment', installmentNumber: null, started: true } });

    expect(screen.getByText('Te toca pagar lo que falta de la inicial.')).toBeInTheDocument();
});

it('lee el comprobante, rellena lo que encontró y envía multipart con los nombres del contrato', async () => {
    const reading = { amount: '1275.00', currency: 'VES', paidAt: '2026-10-05', reference: '00123456', bank: 'Mercantil' };
    const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ reading }), { status: 200 }));
    vi.stubGlobal('fetch', fetch);
    renderPage();
    startUpload();

    const capture = fakeFile('captura.png', 'image/png');
    fireEvent.change(screen.getByLabelText('Captura o PDF del pago'), { target: { files: [capture] } });

    expect(screen.getByRole('status')).toHaveTextContent('Estamos validando tu comprobante');
    expect(screen.getByLabelText('Número de referencia')).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Enviar comprobante' })).toBeDisabled();

    await waitFor(() => expect(screen.getByLabelText('Número de referencia')).toHaveValue('00123456'));
    expect(screen.getByRole('status')).toHaveTextContent('Tomamos de tu captura el monto, la fecha, el banco y la referencia.');
    expect(screen.getAllByText('captura.png')).toHaveLength(1);
    expect(fetch).toHaveBeenCalledWith('/mi-cuenta/apartados/AP-7K3M9QRT/comprobante/leer', expect.objectContaining({ method: 'POST' }));
    expect(screen.queryByText(/\bIA\b|inteligencia artificial/)).toBeNull();

    fireEvent.change(screen.getByLabelText('Nota (opcional)'), { target: { value: 'Cuota de octubre' } });
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
        amount: '1.275,00',
        currency: 'VES',
        note: 'Cuota de octubre',
        file: capture,
    });
});

it('lo que no se pudo leer queda vacío para que el cliente lo complete', async () => {
    const reading = { amount: null, currency: null, paidAt: null, reference: null, bank: null };
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ reading }), { status: 200 })));
    renderPage();
    startUpload();

    fireEvent.change(screen.getByLabelText('Número de referencia'), { target: { value: '999' } });
    fireEvent.change(screen.getByLabelText('Captura o PDF del pago'), { target: { files: [fakeFile('captura.png', 'image/png')] } });

    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('completa lo que esté vacío'));
    expect(screen.getByLabelText('Número de referencia')).toHaveValue('');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('');
    expect(screen.getByRole('button', { name: 'Enviar comprobante' })).toBeEnabled();
});

it('pinta los errores del servidor junto a su campo', async () => {
    const reading = { amount: '30.00', currency: 'USD', paidAt: '2026-10-05', reference: '0012', bank: 'Mercantil' };
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ reading }), { status: 200 })));
    renderPage();
    startUpload();
    fireEvent.change(screen.getByLabelText('Captura o PDF del pago'), { target: { files: [fakeFile('captura.png', 'image/png')] } });
    await waitFor(() => expect(screen.getByLabelText('Número de referencia')).toHaveValue('0012'));

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
