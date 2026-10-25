import Subscribers from '@/pages/backoffice/subscribers/Subscribers';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { planState: null, teamMember: null } }),
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

vi.mock('@/layouts/backoffice-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

it('compara con la semana pasada, dice cuántos ya son clientes y dibuja las semanas', () => {
    render(
        <Subscribers
            subscribers={[]}
            nextPageUrl={null}
            stats={{
                total: 612,
                people: 486,
                last7Days: 28,
                previous7Days: 25,
                customers: 94,
                byCatalog: [{ catalogName: 'Otoño 2026', count: 312 }],
                weekly: [3, 5, 8, 2, 9, 4, 6, 7, 10, 12, 25, 28],
            }}
        />,
    );

    expect(screen.getByText('+12% vs. semana pasada')).toBeInTheDocument();
    expect(screen.getByText('94')).toBeInTheDocument();
    expect(screen.getByText('19% de los suscriptores')).toBeInTheDocument();
    expect(screen.getByText('Esta')).toBeInTheDocument();
    expect(screen.getByText('Esta: 28')).toBeInTheDocument();
});
