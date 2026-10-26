import { PlatformBell } from '@/features/platform/components/PlatformBell';
import { fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

let props: Record<string, unknown> = {};

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props }),
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

it('junta los comprobantes por revisar con los avisos y enlaza cada uno', () => {
    props = {
        platformOperator: {
            pendingReceipts: 4,
            alerts: [
                { key: 'graceEnding', count: 2, tone: 'error', href: '/plataforma/organizaciones?vencidas=1' },
                { key: 'myOpenSupport', count: 1, tone: 'info', href: '/plataforma/soporte' },
            ],
        },
    };

    render(<PlatformBell />);

    expect(screen.getByText('2')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Avisos: 3' }));

    expect(screen.getByText('comprobantes por revisar', { exact: false }).closest('a')).toHaveAttribute('href', '/plataforma/facturas');
    expect(screen.getByText('negocios se suspenden en menos de 3 días', { exact: false }).closest('a')).toHaveAttribute(
        'href',
        '/plataforma/organizaciones?vencidas=1',
    );
    expect(screen.getByText('acceso de soporte tuyo sigue abierto', { exact: false }).closest('a')).toHaveAttribute('href', '/plataforma/soporte');
});

it('sin avisos, lo dice', () => {
    props = { platformOperator: { pendingReceipts: 0, alerts: [] } };

    render(<PlatformBell />);

    fireEvent.click(screen.getByRole('button', { name: 'Sin avisos' }));

    expect(screen.getByText('Nada pendiente. Todo al día.')).toBeInTheDocument();
});
