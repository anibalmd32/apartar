import CustomerShow from '@/pages/backoffice/customers/Show';
import { money, profileProps } from '@/test/t065-fixtures';
import { type CustomerLayawayRow } from '@/types/customers';
import { render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async (original) => ({
    ...(await original<object>()),
    usePage: () => ({ url: '/backoffice/clientes/7', props: { errors: {}, currency: { display: 'usd_primary', rate: { rate: null } } } }),
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

vi.mock('@/layouts/backoffice-layout', () => ({
    default: ({ badge, children }: { badge: ReactNode; children: ReactNode }) => (
        <main>
            <header data-testid="cabecera">{badge}</header>
            {children}
        </main>
    ),
}));

const layaway = (status: string, statusLabel: string): CustomerLayawayRow => ({
    code: 'AP-YSD3M7CJ',
    url: '/backoffice/apartados/AP-YSD3M7CJ',
    status,
    statusLabel,
    productName: 'Nevera Mabe',
    catalogName: null,
    total: money('$165,00', 16_500),
    paid: money('$0,00', 0),
    remaining: money('$165,00', 16_500),
    nextDueDate: '2026-11-01',
    createdAt: null,
    cancellationReason: null,
    cancelledBy: null,
    resendCodeUrl: null,
});

it('con una cuota vencida aún en la gracia, la cabecera no dice «Atrasado» si el apartado sigue activo', () => {
    render(<CustomerShow {...profileProps({ layaways: { active: [layaway('active', 'Activo')], history: [], historyTotal: 0 } })} />);

    expect(within(screen.getByTestId('cabecera')).queryByText('Atrasado')).toBeNull();
    expect(screen.getByText('Activo')).toBeInTheDocument();
});

it('la cabecera dice «Atrasado» cuando el apartado ya está en mora, igual que su tarjeta', () => {
    render(<CustomerShow {...profileProps({ layaways: { active: [layaway('overdue', 'Atrasado')], history: [], historyTotal: 0 } })} />);

    expect(within(screen.getByTestId('cabecera')).getByText('Atrasado')).toBeInTheDocument();
});
