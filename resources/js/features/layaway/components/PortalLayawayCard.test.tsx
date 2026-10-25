import { PortalLayawayCard } from '@/features/layaway/components/PortalLayawayCard';
import { centsUsd } from '@/lib/money';
import { fakeRoute } from '@/test/fakes';
import { type LayawaySummary } from '@/types/portal';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => ({
    usePage: () => ({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: null } } }),
    Link: (await import('@/test/fakes')).FakeLink,
}));

beforeEach(() => vi.stubGlobal('route', fakeRoute));

function money(usd: string) {
    return { cents: centsUsd(100), usd, ves: null };
}

function summary(overrides: Partial<LayawaySummary> = {}): LayawaySummary {
    return {
        code: 'AP-4RT7Y2UI',
        status: 'active',
        statusLabel: 'Al día',
        isTerminal: false,
        acceptsPayments: true,
        productName: 'Sandalias trenzadas',
        quantity: 1,
        total: money('$160,00'),
        paid: money('$125,00'),
        remaining: money('$35,00'),
        nextDueDate: '2026-10-28',
        nextDueDateLabel: '28 de octubre',
        overdueInstallments: 0,
        ...overrides,
    };
}

it('dice cuánto es la próxima cuota y enseña la foto', () => {
    const { container } = render(
        <ul>
            <PortalLayawayCard layaway={summary({ nextAmount: money('$17,50'), imageUrl: '/fotos/sandalias.jpg' })} />
        </ul>,
    );

    expect(screen.getByText('Próxima: 28 de octubre · $17,50')).toBeInTheDocument();
    expect(container.querySelector('img[src="/fotos/sandalias.jpg"]')).not.toBeNull();
});

it('en mora dice desde hace cuántos días', () => {
    render(
        <ul>
            <PortalLayawayCard layaway={summary({ status: 'overdue', statusLabel: 'En mora', overdueInstallments: 1, overdueDays: 7 })} />
        </ul>,
    );

    expect(screen.getByText('1 cuota vencida · venció hace 7 días')).toBeInTheDocument();
});
