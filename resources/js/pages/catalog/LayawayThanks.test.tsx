import { centsUsd } from '@/lib/money';
import LayawayThanks from '@/pages/catalog/LayawayThanks';
import { fakeRoute } from '@/test/fakes';
import { type CatalogView } from '@/types/catalog-view';
import { type RateView } from '@/types/exchange';
import { type LayawayThanksProps } from '@/types/layaway';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { usePage, del } = vi.hoisted(() => ({ usePage: vi.fn(), del: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({ usePage, router: { delete: del }, Head: () => null, Link: (await import('@/test/fakes')).FakeLink }));

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

function renderPage(overrides: Partial<LayawayThanksProps['layaway']> = {}, follow: LayawayThanksProps['follow'] = null) {
    const props: LayawayThanksProps = {
        catalog: CATALOG,
        layaway: {
            code: 'AP-7K3MQ2XH',
            productName: 'Nevera Mabe',
            plan: {
                quantity: 1,
                total: money('$150,00'),
                downPayment: money('$100,00'),
                requiresDownPayment: true,
                financedAmount: money('$50,00'),
                installmentsCount: 3,
                frequencyDays: 15,
                installmentAmount: money('$16,66'),
                lastInstallmentAmount: money('$16,68'),
                installments: [],
            },
            expiresAt: '30 de septiembre a las 10:00 am',
            firstDueDate: null,
            hasEmail: false,
            ...overrides,
        },
        payUrl: '/mi-cuenta/acceso?codigo=AP-7K3MQ2XH',
        follow,
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

it('enseña el código bien visible, el plazo de la inicial y el tercer paso', () => {
    renderPage();

    expect(screen.getByLabelText('Código de apartado AP-7K3MQ2XH')).toHaveTextContent('AP-7K3MQ2XH');
    expect(screen.getByText('30 de septiembre a las 10:00 am')).toBeInTheDocument();
    expect(screen.getByRole('list', { name: 'Pasos del apartado' })).toBeInTheDocument();
    expect(screen.getByText('Paga la inicial').closest('li')).toHaveAttribute('aria-current', 'step');
});

it('no enseña las cuentas de cobro: lleva a pagar con el código ya puesto', () => {
    renderPage();

    expect(screen.queryByText(/Cómo pagarle/)).toBeNull();
    expect(screen.queryByText('0414-1234567')).toBeNull();
    expect(screen.queryByRole('link', { name: /comprobante/ })).toBeNull();
    expect(screen.getByRole('link', { name: /Continuar al pago/ })).toHaveAttribute('href', '/mi-cuenta/acceso?codigo=AP-7K3MQ2XH');
});

it('sin inicial, dice que ya está activo y cuándo vence la primera cuota en vez del plazo de la inicial', () => {
    renderPage({
        plan: {
            quantity: 1,
            total: money('$150,00'),
            downPayment: { cents: centsUsd(0), usd: '$0,00', ves: null },
            requiresDownPayment: false,
            financedAmount: money('$150,00'),
            installmentsCount: 3,
            frequencyDays: 15,
            installmentAmount: money('$50,00'),
            lastInstallmentAmount: null,
            installments: [],
        },
        expiresAt: null,
        firstDueDate: '12 de octubre',
    });

    expect(screen.getByRole('heading', { name: 'No lleva inicial: tu apartado ya está activo' })).toBeInTheDocument();
    expect(screen.getByText('12 de octubre')).toBeInTheDocument();
    expect(screen.queryByText(/paga la inicial/)).toBeNull();
    expect(screen.getByText('Sin inicial')).toBeInTheDocument();
});

it('sin correo, avisa de que es el único canal e invita a anotar el código', () => {
    renderPage({ hasEmail: false });

    const warning = screen.getByRole('alert');
    expect(warning).toHaveTextContent('Anota este código ahora.');
    expect(warning).toHaveTextContent('no te llegará ninguno');
});

it('sin correo, enlaza el acceso al portal con cédula y código', () => {
    renderPage({ hasEmail: false });

    expect(screen.getByRole('link', { name: 'consultar tu apartado' })).toHaveAttribute('href', '/mi-cuenta/acceso');
});

it('con correo, dice que también va por ahí', () => {
    renderPage({ hasEmail: true });

    expect(screen.getByRole('alert')).toHaveTextContent('También te lo enviamos por correo');
    expect(screen.queryByText(/Anota este código ahora/)).toBeNull();
});

it('con cuenta, avisa de que sigue el catálogo y ofrece dejarlo con un clic', () => {
    renderPage({}, { unfollowUrl: '/c/ofertas/seguir' });

    expect(screen.getByText(/Ahora sigues/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Dejar de seguir' }));
    expect(del).toHaveBeenCalledWith('/c/ofertas/seguir', { preserveScroll: true });
});

it('sin cuenta, no habla de seguir', () => {
    renderPage();

    expect(screen.queryByText(/Ahora sigues/)).toBeNull();
});
