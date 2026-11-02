import { BackofficeNav, type NavSection } from '@/layouts/backoffice/nav';
import { TabBar } from '@/layouts/backoffice/tab-bar';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/backoffice/clientes' }),
    Link: ({ href, children, className, ...rest }: { href: string; children: ReactNode; className?: string; 'aria-label'?: string }) => (
        <a href={href} className={className} aria-label={rest['aria-label']}>
            {children}
        </a>
    ),
}));

const TABS: NavSection[] = [
    { label: 'Comprobantes', href: '/backoffice/comprobantes', capability: 'receipts' },
    { label: 'Clientes', href: '/backoffice/clientes', capability: 'customers' },
    { label: 'Cuentas de cobro', href: '/backoffice/cuentas-de-cobro', capability: 'customers' },
];

it('la barra inferior nombra la pestaña primero y en singular si hay un pendiente', () => {
    render(
        <TabBar
            tabs={TABS}
            capabilities={['receipts', 'customers']}
            badges={{ '/backoffice/comprobantes': 1 }}
            onMore={() => undefined}
            moreOpen={false}
        />,
    );

    expect(screen.getByRole('link', { name: 'Comprobantes, 1 pendiente' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /1 pendientes/ })).toBeNull();
});

it('en el menú del celular solo se resalta la sección actual: el hover queda para el escritorio', () => {
    render(<BackofficeNav sections={TABS} capabilities={['receipts', 'customers']} />);

    const other = screen.getByRole('link', { name: 'Cuentas de cobro' });

    expect(other.className).not.toMatch(/(^|\s)hover:bg-/);
    expect(other).toHaveClass('lg:hover:bg-surface-2');
});
