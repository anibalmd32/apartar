import { BusinessBlock } from '@/features/layaway/components/BusinessBlock';
import { centsUsd } from '@/lib/money';
import { fakeRoute } from '@/test/fakes';
import { type BusinessBlock as BusinessBlockData, type PortalTotals } from '@/types/portal';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({ usePage, Link: (await import('@/test/fakes')).FakeLink }));

function money(usd: string, cents: number) {
    return { cents: centsUsd(cents), usd, ves: null };
}

function block(totals: Partial<PortalTotals>): BusinessBlockData {
    return {
        business: { name: 'Tecno Hogar', slug: 'tecno-hogar', logoUrl: null, brandColor: null, brandingEnabled: false },
        closed: false,
        rate: { rate: null, source: 'bcv', sourceLabel: 'BCV', publishedAt: null, isStale: false },
        totals: {
            activeLayaways: 1,
            committed: money('$120,00', 12_000),
            paid: money('$30,00', 3_000),
            remaining: money('$90,00', 9_000),
            overdueInstallments: 0,
            catchUp: money('$0,00', 0),
            nextDueDate: '2026-10-21',
            nextDueDateLabel: '21 oct 2026',
            ...totals,
        },
        layaways: [],
        history: [],
    };
}

beforeEach(() => {
    vi.stubGlobal('route', fakeRoute);
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: { rate: null } } } });
});

it('al día anuncia el próximo vencimiento', () => {
    render(<BusinessBlock block={block({})} />);

    expect(screen.getByText('Próximo vencimiento: 21 oct 2026')).toBeInTheDocument();
});

it('con cuotas vencidas dice desde cuándo y no lo llama próximo vencimiento', () => {
    render(<BusinessBlock block={block({ overdueInstallments: 2, catchUp: money('$45,00', 4_500) })} />);

    expect(screen.getByText('Vencido desde el 21 oct 2026')).toHaveClass('text-error');
    expect(screen.queryByText(/Próximo vencimiento/)).not.toBeInTheDocument();
});

it('si el próximo pago está en revisión lo dice en vez de una fecha', () => {
    render(<BusinessBlock block={block({ nextDueDate: null, nextDueDateLabel: null, paymentInReview: true })} />);

    expect(screen.getByText('Pago en revisión')).toBeInTheDocument();
});

it('con un solo negocio no repite la fecha que ya dice el resumen', () => {
    render(<BusinessBlock block={block({})} showDue={false} />);

    expect(screen.queryByText(/21 oct 2026/)).not.toBeInTheDocument();
});
