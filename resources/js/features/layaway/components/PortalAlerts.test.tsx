import { PortalAlerts } from '@/features/layaway/components/PortalAlerts';
import { centsUsd } from '@/lib/money';
import { type RateView } from '@/types/exchange';
import { type PortalAlert } from '@/types/portal';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

/**
 * Las alertas del portal (`REQ-LAY-023`).
 */

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({ usePage, Link: (await import('@/test/fakes')).FakeLink }));

const NO_RATE: RateView = { rate: null, source: 'bcv', sourceLabel: 'BCV', publishedAt: null, isStale: false };

const money = (usd: string) => ({ cents: centsUsd(3_000), usd, ves: null });

const WINDOW: PortalAlert = {
    kind: 'down_payment_window',
    hoursLeft: 5,
    minutesLeft: 320,
    layawayCode: 'AP-7K3M9QRT',
    businessName: 'Ferretería Oriente',
    actionUrl: '/mi-cuenta/apartados/AP-7K3M9QRT',
};
const OVERDUE: PortalAlert = {
    kind: 'overdue',
    count: 1,
    amount: money('$30.00'),
    businessName: 'Ferretería Oriente',
    actionUrl: '/mi-cuenta/apartados/AP-1',
};
const REJECTED: PortalAlert = { kind: 'receipt_rejected', layawayCode: 'AP-2', businessName: 'Boutique Sur', actionUrl: '/mi-cuenta/apartados/AP-2' };
const SOON: PortalAlert = {
    kind: 'due_soon',
    dueDate: '2026-09-25',
    dueDateLabel: '25 de septiembre',
    amount: money('$30.00'),
    businessName: 'Óptica Centro',
    actionUrl: '/mi-cuenta/apartados/AP-3',
};

beforeEach(() => {
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: NO_RATE } } });
});

/** SP-5 · sin alertas no hay contenedor, ni su hueco. */
it('sin alertas no pinta nada', () => {
    const { container } = render(<PortalAlerts alerts={[]} />);

    expect(container).toBeEmptyDOMElement();
});

/** RB-3 y RB-7 · cada alerta dice su negocio y lleva a la acción que la resuelve. */
it('nombra el negocio y enlaza la acción', () => {
    render(<PortalAlerts alerts={[OVERDUE]} />);

    const alert = screen.getByRole('alert');
    expect(alert).toHaveTextContent('Ferretería Oriente: 1 cuota vencida por $30.00.');
    expect(within(alert).getByRole('link', { name: 'Ver cómo pagar' })).toHaveAttribute('href', '/mi-cuenta/apartados/AP-1');
});

/** RB-5 · la consolidada de varios negocios no nombra ninguno. */
it('la alerta consolidada de vencidas no nombra negocio', () => {
    render(<PortalAlerts alerts={[{ ...OVERDUE, count: 3, amount: money('$90.00'), businessName: null }]} />);

    expect(screen.getByRole('alert')).toHaveTextContent('Entre tus negocios tienes 3 cuotas vencidas por $90.00.');
});

/** SP-2 · con menos de una hora, en minutos. */
it('cuenta la ventana de la inicial en horas, y en minutos al final', () => {
    const { rerender } = render(<PortalAlerts alerts={[WINDOW]} />);

    expect(screen.getByRole('alert')).toHaveTextContent('te quedan 5 horas para pagar la inicial de AP-7K3M9QRT');

    rerender(<PortalAlerts alerts={[{ ...WINDOW, hoursLeft: 0, minutesLeft: 40 }]} />);

    expect(screen.getByRole('alert')).toHaveTextContent('te quedan 40 minutos para pagar la inicial');
});

/** SP-1 · hasta tres, en el orden en que llegan, y el resto tras «ver todos». */
it('enseña tres y el resto al pedirlo', () => {
    render(<PortalAlerts alerts={[WINDOW, OVERDUE, REJECTED, SOON]} />);

    expect(screen.getAllByRole('alert')).toHaveLength(3);
    expect(screen.getAllByRole('alert')[0]).toHaveTextContent('para pagar la inicial');
    expect(screen.queryByText(/vence el 25 de septiembre/)).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Ver todos los avisos (4)' }));

    expect(screen.getAllByRole('alert')).toHaveLength(4);
    expect(screen.getByText(/vence el 25 de septiembre/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Ver todos/ })).toBeNull();
});

it('dice cuántos comprobantes esperan revisión', () => {
    render(<PortalAlerts alerts={[{ kind: 'receipt_pending', count: 2, actionUrl: '/mi-cuenta/apartados/AP-4' }]} />);

    expect(screen.getByRole('alert')).toHaveTextContent('2 comprobantes en revisión.');
});
