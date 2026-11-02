import { PortalLayawayCard } from '@/features/layaway/components/PortalLayawayCard';
import { centsUsd } from '@/lib/money';
import { fakeRoute } from '@/test/fakes';
import { type LayawaySummary } from '@/types/portal';
import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => ({
    usePage: () => ({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: null } } }),
    Link: (await import('@/test/fakes')).FakeLink,
}));

beforeEach(() => {
    vi.stubGlobal('route', fakeRoute);
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-20T12:00:00-04:00'));
});

afterEach(() => vi.useRealTimers());

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

function suggestion(overrides: Partial<NonNullable<LayawaySummary['suggestion']>> = {}): NonNullable<LayawaySummary['suggestion']> {
    return {
        kind: 'installment',
        installmentNumber: 1,
        overdueInstallments: 0,
        amount: money('$8,00'),
        started: false,
        covered: false,
        alternative: null,
        inReview: null,
        ...overrides,
    };
}

it('si lo que envió cubre la cuota, dice que el pago está en revisión en vez de pedirla', () => {
    const inReview = { amount: money('$8,00'), count: 1, submittedOn: '1 nov' };
    render(
        <ul>
            <PortalLayawayCard
                layaway={summary({ nextAmount: money('$8,00'), suggestion: suggestion({ amount: money('$0,00'), covered: true, inReview }) })}
            />
        </ul>,
    );

    expect(screen.getByText('Tu pago está en revisión')).toBeInTheDocument();
    expect(screen.getByText('Enviaste $8,00 el 1 nov')).toBeInTheDocument();
    expect(screen.queryByText(/Próxima:/)).not.toBeInTheDocument();
});

it('con cuotas vencidas propone ponerse al día con el total', () => {
    render(
        <ul>
            <PortalLayawayCard
                layaway={summary({
                    status: 'overdue',
                    statusLabel: 'En mora',
                    overdueInstallments: 2,
                    overdueDays: 12,
                    suggestion: suggestion({ kind: 'overdue', installmentNumber: null, overdueInstallments: 2, amount: money('$45,00') }),
                })}
            />
        </ul>,
    );

    expect(screen.getByText('Ponte al día: $45,00')).toBeInTheDocument();
    expect(screen.getByText('2 cuotas vencidas · venció hace 12 días')).toBeInTheDocument();
});

it('la próxima cuota descuenta lo que está en revisión', () => {
    const inReview = { amount: money('$3,00'), count: 1, submittedOn: '1 nov' };
    render(
        <ul>
            <PortalLayawayCard
                layaway={summary({ nextAmount: money('$8,00'), suggestion: suggestion({ amount: money('$5,00'), started: true, inReview }) })}
            />
        </ul>,
    );

    expect(screen.getByText('Próxima: 28 de octubre · $5,00')).toBeInTheDocument();
    expect(screen.getByText('$3,00 en revisión')).toBeInTheDocument();
});

it('una fecha que ya pasó no se anuncia como próxima', () => {
    render(
        <ul>
            <PortalLayawayCard layaway={summary({ nextAmount: money('$17,50') })} today="2026-10-30" />
        </ul>,
    );

    expect(screen.getByText('Venció el 28 de octubre · $17,50')).toBeInTheDocument();
    expect(screen.queryByText(/Próxima/)).not.toBeInTheDocument();
});

it('la foto lleva borde y el estado va en su propia línea bajo el nombre en el celular', () => {
    const { container } = render(
        <ul>
            <PortalLayawayCard layaway={summary({ imageUrl: '/fotos/sandalias.jpg' })} />
        </ul>,
    );

    expect(container.querySelector('img')).toHaveClass('border');
    const badge = screen.getByText('Al día');
    const name = screen.getByText('Sandalias trenzadas');
    expect(name.parentElement?.parentElement).toContainElement(badge);
    expect(name.parentElement?.parentElement).toHaveClass('flex-col', 'sm:flex-row');
});

it('si el resumen ya dice el próximo pago, la tarjeta no lo repite', () => {
    render(
        <ul>
            <PortalLayawayCard layaway={summary({ nextAmount: money('$17,50') })} showStep={false} />
        </ul>,
    );

    expect(screen.queryByText(/28 de octubre/)).not.toBeInTheDocument();
});
