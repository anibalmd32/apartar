import { PendingReceiptsCard } from '@/features/dashboard/components/PendingReceiptsCard';
import { type DashboardPendingReceipt } from '@/types/dashboard';
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

const receipt = (overrides: Partial<DashboardPendingReceipt> = {}): DashboardPendingReceipt => ({
    id: 1,
    reviewUrl: '/backoffice/comprobantes/1',
    customerName: 'Ana Pérez',
    layawayCode: 'AP-7K3M9QRT',
    methodLabel: 'Pago móvil',
    declared: 'Bs. 1.643,40',
    submittedAt: new Date(Date.now() - 3 * 86_400_000).toISOString(),
    overdue: true,
    file: { url: '/f', kind: 'image' },
    ...overrides,
});

it('cada comprobante lleva a su revisión, con el atraso marcado', () => {
    render(
        <PendingReceiptsCard
            receipts={[
                receipt(),
                receipt({ id: 2, reviewUrl: '/backoffice/comprobantes/2', overdue: false, customerName: 'Luis', declared: '$120,00' }),
            ]}
            inboxUrl="/backoffice/comprobantes"
        />,
    );

    expect(screen.getByRole('link', { name: /Ana Pérez/ })).toHaveAttribute('href', '/backoffice/comprobantes/1');
    expect(screen.getAllByText('Atrasado')).toHaveLength(1);
    expect(screen.getByText('Bs. 1.643,40')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Ver todos' })).toHaveAttribute('href', '/backoffice/comprobantes');
});

it('sin pendientes, o sin permiso para verlos, no se pinta', () => {
    const { container, rerender } = render(<PendingReceiptsCard receipts={[]} inboxUrl="/x" />);

    expect(container).toBeEmptyDOMElement();

    rerender(<PendingReceiptsCard receipts={null} inboxUrl="/x" />);

    expect(container).toBeEmptyDOMElement();
});
