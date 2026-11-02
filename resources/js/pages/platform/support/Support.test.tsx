import SupportCustomers from '@/pages/platform/support/Customers';
import SupportLayaways from '@/pages/platform/support/Layaways';
import { render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

vi.mock('@/layouts/platform-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

const ORGANIZATION = { id: 4, name: 'Moda Valentina', slug: 'moda-valentina' };

it('los apartados de soporte vuelven al negocio y se apilan en el celular sin montos en cero', () => {
    render(
        <SupportLayaways
            organization={ORGANIZATION}
            layaways={[
                {
                    code: 'AP-YZE96F5Q',
                    customer: 'José Gregorio Martínez',
                    product: 'Lentes de sol',
                    status: 'Activo',
                    totalCents: 123_450,
                    paidCents: 0,
                    remainingCents: 123_450,
                    createdAt: null,
                },
            ]}
        />,
    );

    expect(screen.getByRole('link', { name: 'Moda Valentina' })).toHaveAttribute('href', '/plataforma/organizaciones/moda-valentina');
    expect(screen.getByRole('table').parentElement).toHaveClass('table-stack');
    expect(screen.getByText('AP-YZE96F5Q')).toHaveClass('whitespace-nowrap');

    const cells = within(screen.getAllByRole('row')[1]!).getAllByRole('cell');
    expect(cells[0]).toHaveAttribute('data-cell', 'primary');
    expect(cells[2]).toHaveTextContent('$1.234,50');
    expect(cells[3]).toBeEmptyDOMElement();
});

it('los clientes de soporte muestran la cédula y el teléfono con formato', () => {
    render(
        <SupportCustomers
            organization={ORGANIZATION}
            customers={[{ fullName: 'María Cliente', documentNumber: 'V12345678', phone: '04141234567', email: null, createdAt: null }]}
        />,
    );

    expect(screen.getByRole('link', { name: 'Moda Valentina' })).toHaveAttribute('href', '/plataforma/organizaciones/moda-valentina');
    expect(screen.getByText('V-12.345.678')).toHaveAttribute('data-label', 'Documento');
    expect(screen.getByText('0414-1234567')).toHaveAttribute('data-label', 'Teléfono');
    expect(screen.getByRole('table').parentElement).toHaveClass('table-stack');
});
