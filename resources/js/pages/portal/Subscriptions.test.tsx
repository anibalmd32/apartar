import Subscriptions from '@/pages/portal/Subscriptions';
import { fakeRoute } from '@/test/fakes';
import { type SubscriptionRow } from '@/types/account';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { usePage, patch, del } = vi.hoisted(() => ({ usePage: vi.fn(), patch: vi.fn(), del: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({
    usePage,
    router: { patch, delete: del },
    Head: () => null,
    Link: (await import('@/test/fakes')).FakeLink,
}));

function row(overrides: Partial<SubscriptionRow> = {}): SubscriptionRow {
    return {
        catalogId: 7,
        catalogSlug: 'ofertas',
        catalogName: 'Ofertas de octubre',
        businessName: 'Ferretería Oriente',
        active: true,
        muted: false,
        productsCount: 12,
        newCount: 3,
        subscribedAt: '2026-10-01T10:00:00-04:00',
        url: 'http://localhost/c/ofertas',
        ...overrides,
    };
}

function renderPage(subscriptions: SubscriptionRow[]) {
    return render(<Subscriptions account={{ displayName: 'María Pérez' }} subscriptions={subscriptions} />);
}

beforeEach(() => {
    usePage.mockReturnValue({ props: {} });
    vi.stubGlobal('route', fakeRoute);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

it('enseña cada catálogo con su negocio, sus productos y los nuevos', () => {
    renderPage([
        row(),
        row({ catalogId: 8, catalogSlug: 'bodega', catalogName: 'Bodega', businessName: 'Bodega Central', newCount: 0, productsCount: 1 }),
    ]);

    const ofertas = within(screen.getByRole('article', { name: 'Ofertas de octubre' }));
    expect(ofertas.getByRole('link', { name: 'Ofertas de octubre' })).toHaveAttribute('href', 'http://localhost/c/ofertas');
    expect(ofertas.getByText('Ferretería Oriente')).toBeInTheDocument();
    expect(ofertas.getByText('12 productos')).toBeInTheDocument();
    expect(ofertas.getByText('3 nuevos')).toBeInTheDocument();

    const bodega = within(screen.getByRole('article', { name: 'Bodega' }));
    expect(bodega.getByText('1 producto')).toBeInTheDocument();
    expect(bodega.queryByText(/nuevo/)).toBeNull();
});

it('uno inactivo se queda en la lista, sin enlace ni cifras', () => {
    renderPage([row({ active: false, url: null, productsCount: 0, newCount: 0 })]);

    const card = within(screen.getByRole('article', { name: 'Ofertas de octubre' }));
    expect(card.queryByRole('link')).toBeNull();
    expect(card.getByText('No disponible ahora')).toBeInTheDocument();
    expect(card.queryByText(/productos/)).toBeNull();
});

it('silenciar manda el PATCH sin sacar el catálogo', () => {
    renderPage([row()]);

    fireEvent.click(screen.getByRole('checkbox', { name: 'Avisarme de novedades' }));

    expect(patch).toHaveBeenCalledWith('/mi-cuenta/suscripciones/7', { muted: true }, { preserveScroll: true });
});

it('dejar de seguir va por el DELETE del catálogo', () => {
    renderPage([row({ active: false, url: null })]);

    fireEvent.click(screen.getByRole('button', { name: 'Dejar de seguir' }));

    expect(del).toHaveBeenCalledWith('/c/ofertas/seguir', { preserveScroll: true });
});

it('sin suscripciones, explica cómo seguir uno', () => {
    renderPage([]);

    expect(screen.getByText('Todavía no sigues ningún catálogo.')).toBeInTheDocument();
});
