import { centsUsd } from '@/lib/money';
import LayawayThanks from '@/pages/catalog/LayawayThanks';
import { fakeRoute } from '@/test/fakes';
import { type CatalogView } from '@/types/catalog-view';
import { type RateView } from '@/types/exchange';
import { type LayawayThanksProps } from '@/types/layaway';
import { type PaymentAccountView } from '@/types/payment';
import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

/**
 * La confirmación del apartado (`REQ-LAY-001` RB-10).
 */

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({ usePage, Head: () => null, Link: (await import('@/test/fakes')).FakeLink }));

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

const ACCOUNT: PaymentAccountView = {
    id: 1,
    method: 'pago_movil',
    methodLabel: 'Pago móvil',
    identifierLabel: 'Teléfono',
    bank: 'Banesco',
    holderName: 'Ana Pérez',
    holderDocument: 'V-12.345.678',
    identifier: '0414-1234567',
    instructions: null,
    isActive: true,
    position: 1,
};

function renderPage(overrides: Partial<LayawayThanksProps['layaway']> = {}, receiptUrl: string | null = null) {
    const props: LayawayThanksProps = {
        catalog: CATALOG,
        layaway: {
            code: 'AP-7K3MQ2XH',
            productName: 'Nevera Mabe',
            plan: {
                quantity: 1,
                total: money('$150.00'),
                downPayment: money('$100.00'),
                financedAmount: money('$50.00'),
                installmentsCount: 3,
                frequencyDays: 15,
                installmentAmount: money('$16.66'),
                lastInstallmentAmount: money('$16.68'),
                installments: [],
            },
            expiresAt: '30 de septiembre a las 10:00 am',
            hasEmail: false,
            ...overrides,
        },
        paymentAccounts: [ACCOUNT],
        receiptUrl,
    };

    return render(<LayawayThanks {...props} />);
}

beforeEach(() => {
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: BCV } } });
    vi.stubGlobal('route', fakeRoute);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

it('enseña el código bien visible, el plazo de la inicial y dónde pagar', () => {
    renderPage();

    expect(screen.getByLabelText('Código de apartado AP-7K3MQ2XH')).toHaveTextContent('AP-7K3MQ2XH');
    expect(screen.getByText('30 de septiembre a las 10:00 am')).toBeInTheDocument();
    expect(screen.getByText('0414-1234567')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Cómo pagarle a Ferretería Oriente' })).toBeInTheDocument();
});

/** RB-10 · sin correo, esta pantalla es el único canal, y lo dice. */
it('sin correo, avisa de que es el único canal e invita a anotar el código', () => {
    renderPage({ hasEmail: false });

    const warning = screen.getByRole('alert');
    expect(warning).toHaveTextContent('Anota este código ahora.');
    expect(warning).toHaveTextContent('no te llegará ninguno');
});

it('con correo, dice que también va por ahí', () => {
    renderPage({ hasEmail: true });

    expect(screen.getByRole('alert')).toHaveTextContent('También te lo enviamos por correo');
    expect(screen.queryByText(/Anota este código ahora/)).toBeNull();
});

/** El comprobante es de `T-059`: sin destino, no se pinta el botón. */
it('no pinta el botón del comprobante hasta que exista su destino', () => {
    renderPage();
    expect(screen.queryByRole('link', { name: /comprobante/ })).toBeNull();

    renderPage({}, '/c/ofertas/apartar/AP-7K3MQ2XH/comprobante');
    expect(screen.getByRole('link', { name: 'Subir el comprobante de pago' })).toBeInTheDocument();
});
