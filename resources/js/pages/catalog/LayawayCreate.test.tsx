import { centsUsd } from '@/lib/money';
import LayawayCreate from '@/pages/catalog/LayawayCreate';
import { fakeRoute } from '@/test/fakes';
import { type CatalogView, type ProductDetail } from '@/types/catalog-view';
import { type RateView } from '@/types/exchange';
import { type LayawayCreateProps, type LayawayPlanView } from '@/types/layaway';
import { router } from '@inertiajs/core';
import type * as InertiaReact from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof InertiaReact>()),
    usePage,
    Head: () => null,
    Link: (await import('@/test/fakes')).FakeLink,
}));

const BCV: RateView = { rate: 40, source: 'bcv', sourceLabel: 'BCV', publishedAt: '2026-09-18T09:00:00-04:00', isStale: false };

function money(usd: string) {
    return { cents: centsUsd(100), usd, ves: null };
}

const CATALOG: CatalogView = {
    slug: 'ofertas',
    name: 'Ofertas de septiembre',
    description: null,
    cover: null,
    business: { name: 'Ferretería Oriente', slug: 'ferreteria', logoUrl: null, brandColor: null, brandingEnabled: false },
    shareUrl: 'http://localhost/c/ofertas',
    expiresAt: null,
    showsPlatformBadge: false,
};

const PLAN: LayawayPlanView = {
    quantity: 1,
    total: money('$150,00'),
    downPayment: money('$100,00'),
    financedAmount: money('$50,00'),
    installmentsCount: 3,
    frequencyDays: 15,
    installmentAmount: money('$16,66'),
    lastInstallmentAmount: money('$16,68'),
    installments: [
        { number: 1, estimatedDueDate: '12 de octubre de 2026', amount: money('$16,66') },
        { number: 2, estimatedDueDate: '27 de octubre de 2026', amount: money('$16,66') },
        { number: 3, estimatedDueDate: '11 de noviembre de 2026', amount: money('$16,68') },
    ],
};

function product(overrides: Partial<ProductDetail> = {}): ProductDetail {
    return {
        slug: 'nevera',
        name: 'Nevera Mabe',
        price: money('$150,00'),
        compareAtPrice: null,
        downPayment: money('$100,00'),
        installmentAmount: money('$16,66'),
        lastInstallmentAmount: money('$16,68'),
        installmentsCount: 3,
        frequencyDays: 15,
        categoryName: '',
        tags: [],
        inStock: true,
        catalogSlug: 'ofertas',
        description: '',
        sku: 'NEV-1',
        images: [],
        specificationGroups: [],
        maxQuantity: 3,
        paymentAccounts: [],
        canBeReserved: true,
        unavailableReason: null,
        ...overrides,
    };
}

function renderPage(overrides: Partial<LayawayCreateProps> = {}) {
    const props: LayawayCreateProps = {
        catalog: CATALOG,
        product: product(),
        plan: PLAN,
        quantity: 1,
        consent: { businessName: 'Ferretería Oriente' },
        account: null,
        ...overrides,
    };

    return render(<LayawayCreate {...props} />);
}

function fillRequired() {
    fireEvent.change(screen.getByLabelText('Nombre y apellido'), { target: { value: 'María González' } });
    fireEvent.change(screen.getByLabelText('Cédula o RIF'), { target: { value: '12345678' } });
    fireEvent.click(screen.getByRole('button', { name: 'Prefijo' }));
    fireEvent.click(screen.getByRole('option', { name: /0414/ }));
    fireEvent.change(screen.getByLabelText('Teléfono celular'), { target: { value: '1234567' } });
    fireEvent.click(screen.getByRole('checkbox'));
}

let visit: ReturnType<typeof vi.spyOn>;
let reload: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
    usePage.mockReturnValue({
        props: {
            catalogs: {
                banks: [],
                mobilePrefixes: [
                    { id: 1, prefix: '0412', carrier: 'Digitel', logoUrl: '/images/carriers/icons/digitel.svg' },
                    { id: 2, prefix: '0414', carrier: 'Movistar', logoUrl: '/images/carriers/icons/movistar.svg' },
                ],
                documentPrefixes: [{ id: 1, code: 'V', name: 'Venezolano' }],
            },
            currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: BCV },
            errors: {},
        },
    });
    vi.stubGlobal('route', fakeRoute);
    visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    reload = vi.spyOn(router, 'reload').mockImplementation(() => undefined);
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

it('llega con el producto, cantidad 1, el plan del servidor y dónde pagar', () => {
    renderPage();

    expect(screen.getByRole('heading', { name: 'Aparta tu producto' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Nevera Mabe' })).toBeInTheDocument();
    expect(screen.getByLabelText('Cantidad')).toHaveValue(1);
    expect(screen.getByText('$150,00')).toBeInTheDocument();
    expect(screen.getByText('Última cuota')).toBeInTheDocument();
    expect(screen.getByText(/todavía no ha publicado sus formas de pago/)).toBeInTheDocument();
});

it('manda la misma clave de idempotencia en cada envío', () => {
    renderPage();
    fillRequired();

    fireEvent.click(screen.getByRole('button', { name: 'Apartar' }));

    const first = visit.mock.calls[0];
    expect(first?.[0]).toBe('/c/ofertas/apartar/nevera');

    act(() => (first?.[1] as { onFinish?: () => void } | undefined)?.onFinish?.());
    fireEvent.click(screen.getByRole('button', { name: 'Apartar' }));

    const key = (call: unknown[] | undefined): unknown => (call?.[1] as { data: Record<string, unknown> }).data.idempotencyKey;

    expect(visit).toHaveBeenCalledTimes(2);
    expect(key(visit.mock.calls[0])).toMatch(/^[0-9a-f-]{36}$/);
    expect(key(visit.mock.calls[1])).toBe(key(visit.mock.calls[0]));
});

it('manda los campos con los nombres del contrato', () => {
    renderPage();
    fillRequired();

    fireEvent.click(screen.getByRole('button', { name: 'Apartar' }));

    expect((visit.mock.calls[0]?.[1] as { method: string; data: Record<string, unknown> }).data).toMatchObject({
        quantity: 1,
        fullName: 'María González',
        documentNumber: 'V12345678',
        phone: '04141234567',
        email: '',
        termsAccepted: true,
    });
});

it('pide el plan de la cantidad nueva al servidor, y no lo multiplica', () => {
    vi.useFakeTimers();
    renderPage();

    fireEvent.change(screen.getByLabelText('Cantidad'), { target: { value: '2' } });
    expect(reload).not.toHaveBeenCalled();

    act(() => vi.advanceTimersByTime(300));

    expect(reload).toHaveBeenCalledWith({ only: ['plan'], data: { quantity: 2 } });
    expect(screen.getByRole('region', { name: 'Tu plan de pago' })).toHaveAttribute('aria-busy', 'true');
});

it('acota la cantidad al tope que viene del servidor', () => {
    renderPage();

    fireEvent.change(screen.getByLabelText('Cantidad'), { target: { value: '9' } });
    expect(screen.getByLabelText('Cantidad')).toHaveValue(3);

    fireEvent.change(screen.getByLabelText('Cantidad'), { target: { value: '0' } });
    expect(screen.getByLabelText('Cantidad')).toHaveValue(1);
});

it('solo ofrece prefijos de celular, con su operadora', () => {
    renderPage();

    fireEvent.click(screen.getByRole('button', { name: 'Prefijo' }));

    expect(screen.getAllByRole('option').map((option) => option.textContent)).toEqual(['0412Digitel', '0414Movistar']);
});

it('enlaza los documentos en pestaña nueva y dice que el compromiso es con el negocio', () => {
    renderPage();

    expect(screen.getByRole('checkbox')).toBeRequired();
    expect(screen.getByText(/El compromiso de pago es con/)).toHaveTextContent('Ferretería Oriente, no con Apartar');
    expect(screen.getByText(/Guardamos la fecha y la IP/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Términos de servicio' })).toHaveAttribute('target', '_blank');
    expect(screen.getByRole('link', { name: 'Política de privacidad' })).toHaveAttribute('href', '/privacidad');
});

it('sin poder apartar, dice el motivo y no pinta el formulario', () => {
    renderPage({ product: product({ canBeReserved: false, unavailableReason: 'Agotado: ahora mismo no quedan unidades para apartar.' }) });

    expect(screen.getByText('Agotado: ahora mismo no quedan unidades para apartar.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Apartar' })).toBeNull();
});
