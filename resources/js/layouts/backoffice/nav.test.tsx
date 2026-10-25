import { BackofficeNav, type NavSection } from '@/layouts/backoffice/nav';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/backoffice' }),
    Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
}));

const SECTIONS: NavSection[] = [
    { label: 'Panel', href: '/backoffice', capability: 'any' },
    { label: 'Comprobantes', href: '/backoffice/comprobantes', capability: 'receipts' },
];

it('pinta la cifra de pendientes junto a la bandeja', () => {
    render(<BackofficeNav sections={SECTIONS} capabilities={['receipts']} badges={{ '/backoffice/comprobantes': 3 }} />);

    expect(screen.getByRole('link', { name: 'Comprobantes 3 pendientes' })).toHaveAttribute('href', '/backoffice/comprobantes');
});

it('sin pendientes, o sin cifra, no pinta badge', () => {
    const { rerender } = render(<BackofficeNav sections={SECTIONS} capabilities={['receipts']} badges={{ '/backoffice/comprobantes': 0 }} />);

    expect(screen.getByRole('link', { name: 'Comprobantes' })).toBeInTheDocument();

    rerender(<BackofficeNav sections={SECTIONS} capabilities={['receipts']} />);

    expect(screen.getByRole('link', { name: 'Comprobantes' })).toBeInTheDocument();
    expect(screen.queryByText(/pendientes/)).not.toBeInTheDocument();
});
