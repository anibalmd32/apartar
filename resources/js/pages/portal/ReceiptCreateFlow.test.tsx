import ReceiptCreate from '@/pages/portal/ReceiptCreate';
import { fakeRoute } from '@/test/fakes';
import { fakeFile, receiptProps, stubObjectUrls } from '@/test/receipt-fixtures';
import { type ReceiptCreateProps } from '@/types/receipt';
import { router } from '@inertiajs/core';
import type * as InertiaReact from '@inertiajs/react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
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

const startUpload = () => fireEvent.click(screen.getByRole('button', { name: 'Ya pagué, subir el comprobante' }));

it('la ficha del apartado muestra la foto del producto', () => {
    const base = receiptProps();
    renderPage({ layaway: { ...base.layaway, imageUrl: '/media/nevera-thumb.webp' } });

    expect(screen.getByRole('region', { name: 'Apartado' }).querySelector('img')).toHaveAttribute('src', '/media/nevera-thumb.webp');
});

it('primero dice cuánto pagar y a dónde, y el formulario aparece al decir que ya pagó', () => {
    renderPage();

    expect(screen.getByRole('heading', { level: 2, name: /Paga \$30,00 a:/ })).toBeInTheDocument();
    expect(screen.getByText('La cuota 2')).toBeInTheDocument();
    expect(screen.getByText('J-12345678-9', { exact: false })).toBeInTheDocument();
    expect(screen.queryByLabelText('Captura o PDF del pago')).toBeNull();
    expect(screen.queryByText(/Cuenta a la que pagaste/)).toBeNull();

    startUpload();

    expect(screen.getByRole('heading', { name: 'Sube el comprobante' })).toBeInTheDocument();
    expect(screen.getByLabelText('Captura o PDF del pago')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Ya pagué, subir el comprobante' })).toBeNull();
});

it('si sustituye un comprobante rechazado, abre directo el formulario', () => {
    renderPage({ replaces: { submittedAt: '2026-10-01', reason: 'La referencia no coincide' } });

    expect(screen.getByLabelText('Captura o PDF del pago')).toBeInTheDocument();
});

it('antes de enviar, pide en el formulario lo que falta sin subir nada', () => {
    renderPage();
    startUpload();

    fireEvent.click(screen.getByRole('button', { name: 'Enviar comprobante' }));

    expect(visit).not.toHaveBeenCalled();
    const field = (label: string) => screen.getByLabelText(label).closest('fieldset') as HTMLElement;
    expect(within(field('Captura o PDF del pago')).getByRole('alert')).toHaveTextContent('Sube la captura o el PDF de tu pago.');
    expect(within(field('Número de referencia')).getByRole('alert')).toHaveTextContent('Escribe el número de referencia.');
    expect(screen.getByText('Indica el banco desde el que pagaste.')).toBeInTheDocument();
});

it('la captura se sube tocando una zona grande, se ve en miniatura y se puede quitar', () => {
    renderPage();
    startUpload();

    expect(screen.getByText('Toca para subir la captura')).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Captura o PDF del pago'), { target: { files: [fakeFile('pago.pdf', 'application/pdf')] } });

    const chosen = screen.getByRole('group', { name: 'Archivo elegido' });
    expect(chosen).toHaveTextContent('pago.pdf');
    expect(screen.getAllByText('pago.pdf')).toHaveLength(1);
    expect(within(chosen).getByText('Cambiar')).toBeInTheDocument();

    fireEvent.click(within(chosen).getByRole('button', { name: 'Quitar' }));
    expect(screen.queryByRole('group', { name: 'Archivo elegido' })).toBeNull();
    expect(screen.getByText('Toca para subir la captura')).toBeInTheDocument();
});

it('un PDF no se lee: el cliente escribe los datos', () => {
    const fetch = vi.fn();
    vi.stubGlobal('fetch', fetch);
    renderPage();
    startUpload();

    fireEvent.change(screen.getByLabelText('Captura o PDF del pago'), { target: { files: [fakeFile('pago.pdf', 'application/pdf')] } });

    expect(fetch).not.toHaveBeenCalled();
    expect(screen.queryByText(/validando/)).toBeNull();
});

it('sin cuentas, envía la cuenta como null', () => {
    renderPage({ paymentAccounts: [] });
    startUpload();

    fireEvent.change(screen.getByLabelText('Método de pago'), { target: { value: 'efectivo_usd' } });
    fireEvent.change(screen.getByLabelText('Captura o PDF del pago'), { target: { files: [fakeFile('pago.pdf', 'application/pdf')] } });
    fireEvent.click(screen.getByRole('button', { name: 'Enviar comprobante' }));

    expect(lastVisit()[1].data).toMatchObject({
        paymentAccount: null,
        method: 'efectivo_usd',
        bank: '',
        reference: '',
        currency: 'USD',
        amount: '30,00',
    });
});

it('lo que la captura rellena deja de marcarse como faltante', async () => {
    const reading = { amount: '1275.00', currency: 'VES', paidAt: '2026-10-05', reference: '00123456', bank: 'Mercantil' };
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ reading }), { status: 200 })));
    renderPage();
    startUpload();

    fireEvent.click(screen.getByRole('button', { name: 'Enviar comprobante' }));
    expect(screen.getByText('Escribe el número de referencia.')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Captura o PDF del pago'), { target: { files: [fakeFile('captura.png', 'image/png')] } });

    await waitFor(() => expect(screen.getByLabelText('Número de referencia')).toHaveValue('00123456'));
    expect(screen.queryByText('Escribe el número de referencia.')).toBeNull();
    expect(screen.queryByText('Indica el banco desde el que pagaste.')).toBeNull();
    expect(screen.queryByText('Sube la captura o el PDF de tu pago.')).toBeNull();
});
