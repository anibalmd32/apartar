import ReceiptCreate from '@/pages/portal/ReceiptCreate';
import { fakeRoute } from '@/test/fakes';
import { fakeFile, PAGO_MOVIL, receiptProps, stubObjectUrls, ZELLE } from '@/test/receipt-fixtures';
import { type ReceiptCreateProps } from '@/types/receipt';
import { router } from '@inertiajs/core';
import type * as InertiaReact from '@inertiajs/react';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

/**
 * Reportar un pago desde el portal (`REQ-PAY-001`, `T-059`).
 *
 * Con el `useForm` de verdad: se intercepta el `router` de Inertia, por donde
 * sale el envío, para ver **qué** se manda. Lo que el servidor hace con ello
 * está en los tests de `ReceiptController`.
 */

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
    expect(summary).toHaveTextContent('Te falta: $150.00');
    expect(screen.getByRole('link', { name: '← Volver al apartado' })).toHaveAttribute('href', '/mi-cuenta/apartados/AP-7K3M9QRT');
    expect(screen.getByText('María González')).toBeInTheDocument();
});

/** RB-3 · con una sola cuenta, va elegida, con su método, su moneda y la cifra sugerida en esa moneda. */
it('preselecciona la única cuenta, su método y la sugerencia en su moneda', () => {
    renderPage();

    expect(screen.getByLabelText('Cuenta a la que pagaste')).toHaveValue('7');
    expect(screen.getByRole('option', { name: 'Pago móvil · Banesco · 0414-1234567' })).toBeInTheDocument();
    expect(screen.getByLabelText('Método de pago')).toHaveValue('pago_movil');
    expect(screen.getByLabelText('Moneda')).toHaveValue('VES');
    expect(screen.getByLabelText('Monto que pagaste')).toHaveValue('1275.00');
    expect(screen.getByText('J-12345678-9', { exact: false })).toBeInTheDocument();
});

it('con varias cuentas no elige ninguna, y elegir una pone su método', () => {
    renderPage({ paymentAccounts: [PAGO_MOVIL, ZELLE] });

    expect(screen.getByLabelText('Cuenta a la que pagaste')).toHaveValue('');
    expect(screen.getByLabelText('Método de pago')).toHaveValue('');

    fireEvent.change(screen.getByLabelText('Cuenta a la que pagaste'), { target: { value: '9' } });

    expect(screen.getByLabelText('Método de pago')).toHaveValue('zelle');
    expect(screen.getByLabelText('Moneda')).toHaveValue('USD');
    expect(screen.getByText('pagos@oriente.com')).toBeInTheDocument();
});

/** SP-8 · sin cuentas publicadas, el campo no existe y se pide decirlo en la nota. */
it('sin cuentas de cobro, quita el campo y avisa', () => {
    renderPage({ paymentAccounts: [] });

    expect(screen.queryByLabelText('Cuenta a la que pagaste')).toBeNull();
    expect(screen.getByRole('alert')).toHaveTextContent('Este negocio no tiene cuentas de cobro publicadas: indica en la nota a dónde pagaste.');
});

/** RB-4, SP-5 · la fecha no sale del rango del servidor y arranca en hoy. */
it('acota la fecha del pago y arranca en hoy', () => {
    renderPage();

    const date = screen.getByLabelText('Fecha del pago');
    expect(date).toHaveAttribute('type', 'date');
    expect(date).toHaveAttribute('min', '2026-09-10');
    expect(date).toHaveAttribute('max', '2026-10-05');
    expect(date).toHaveValue('2026-10-05');
});

/** RB-9 · la sugerencia se enseña como ayuda. */
it('enseña el monto sugerido como ayuda', () => {
    renderPage();

    expect(screen.getByText(/Te sugerimos la cuota 2:/)).toHaveTextContent('Te sugerimos la cuota 2: $30.00. Si pagaste otro monto, escríbelo.');
});

/** El contrato del `POST`: multipart, con estos nombres y el `File`. */
it('envía multipart con los nombres del contrato y el archivo', () => {
    renderPage();

    const capture = fakeFile('captura.png', 'image/png');
    fireEvent.change(screen.getByLabelText('Banco'), { target: { value: 'Mercantil' } });
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

/** SP-1, SP-2 · cada rechazo junto a su campo, el duplicado incluido; el botón gira mientras se envía. */
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
