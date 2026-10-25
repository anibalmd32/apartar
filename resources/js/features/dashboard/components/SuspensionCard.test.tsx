import { SuspensionCard } from '@/features/dashboard/components/SuspensionCard';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

const SUSPENSION = {
    suspendedAt: '2026-10-18',
    graceEndsAt: '2026-10-17',
    invoice: {
        number: 'F-2026-0007',
        planName: 'Pro',
        kind: 'Renovación',
        periodStart: '2026-09-20T00:00:00Z',
        periodEnd: '2026-10-20T00:00:00Z',
        dueDate: '2026-10-10',
        daysOverdue: 11,
        amount: '$15,00',
        amountVes: 'Bs. 547,80',
    },
};

it('cuenta la línea de tiempo y lleva a pagar la factura', () => {
    render(<SuspensionCard suspension={SUSPENSION} invoicesUrl="/backoffice/suscripcion/facturas" />);

    expect(screen.getByText('10/10')).toBeInTheDocument();
    expect(screen.getByText('18/10')).toBeInTheDocument();
    expect(screen.getByText('F-2026-0007 · vencida hace 11 días')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Subir comprobante de pago' })).toHaveAttribute('href', '/backoffice/suscripcion/facturas');
});

it('sin acceso a facturas, dice a quién pedírselo', () => {
    render(<SuspensionCard suspension={SUSPENSION} invoicesUrl={null} />);

    expect(screen.queryByRole('link', { name: 'Subir comprobante de pago' })).toBeNull();
    expect(screen.getByText(/Pídele a la persona dueña/)).toBeInTheDocument();
});
