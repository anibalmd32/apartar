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
    requiresDownPayment: true,
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
        specifications: [],
        maxQuantity: 3,
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

beforeEach(() => {
    usePage.mockReturnValue({
        props: {
            catalogs: {
                banks: [],
                mobilePrefixes: [
                    { id: 1, prefix: '0412', carrier: 'Digitel', logoUrl: null },
                    { id: 2, prefix: '0414', carrier: 'Movistar', logoUrl: null },
                ],
            },
            currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: BCV },
            errors: {},
        },
    });
    vi.stubGlobal('route', fakeRoute);
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

it('con cuenta precarga el nombre editable y el correo bloqueado, y deja documento y teléfono a la persona', () => {
    renderPage({ account: { fullName: 'María Pérez', email: 'maria@gmail.test' } });

    const name = screen.getByLabelText('Nombre y apellido');
    const email = screen.getByLabelText('Correo (opcional)');

    expect(name).toHaveValue('María Pérez');
    expect(name).not.toHaveAttribute('readonly');
    expect(email).toHaveValue('maria@gmail.test');
    expect(email).toHaveAttribute('readonly');
    expect(screen.getByLabelText('Cédula o RIF')).toHaveValue('');
    expect(screen.getByLabelText('Teléfono celular')).toHaveValue('');
});

it('sin cuenta deja los cuatro campos libres', () => {
    renderPage();

    expect(screen.getByLabelText('Nombre y apellido')).toHaveValue('');
    expect(screen.getByLabelText('Correo (opcional)')).not.toHaveAttribute('readonly');
});

it('borra el error de cada campo en cuanto se vuelve a escribir en él', () => {
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderPage();

    fireEvent.click(screen.getByRole('button', { name: 'Apartar' }));
    const options = visit.mock.calls[0]?.[1] as { onError?: (errors: Record<string, string>) => void };
    act(() => options.onError?.({ fullName: 'Escribe tu nombre.', email: 'Revisa el correo.' }));

    expect(screen.getByText('Escribe tu nombre.')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Nombre y apellido'), { target: { value: 'M' } });

    expect(screen.queryByText('Escribe tu nombre.')).toBeNull();
    expect(screen.getByText('Revisa el correo.')).toBeInTheDocument();
});

it('el formulario va primero y las cuotas se abren con «Ver tus cuotas»', () => {
    renderPage();

    const form = screen.getByRole('heading', { name: 'Tus datos' });
    const summary = screen.getByRole('region', { name: 'Tu plan de pago' });
    expect(form.compareDocumentPosition(summary) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(summary.parentElement).not.toHaveClass('max-lg:row-start-1');

    const toggle = screen.getByText(/Ver tus cuotas/).closest('details');
    expect(toggle).not.toHaveAttribute('open');
    fireEvent.click(screen.getByText(/Ver tus cuotas/));
    expect(toggle).toHaveAttribute('open');
});
