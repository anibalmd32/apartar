import { ThreadContextPanel } from '@/features/chat/components/ThreadContextHeader';
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

it('dice la mora con su importe, el próximo vencimiento y enlaza cada apartado', () => {
    render(
        <ThreadContextPanel
            context={{
                customerName: 'Ana Pérez',
                phone: '0412-883-4410',
                activeLayaways: 2,
                remaining: '$125,00',
                overdueInstallments: 1,
                overdueAmount: '$30,00',
                nextDueDate: '2026-10-28',
                layaways: [
                    {
                        code: 'AP-7K3M9QRT',
                        productName: 'Bolso tote de cuero',
                        status: 'overdue',
                        statusLabel: 'En mora',
                        url: '/backoffice/apartados/AP-7K3M9QRT',
                    },
                ],
                profileUrl: '/backoffice/clientes/7',
            }}
        />,
    );

    expect(screen.getByText('1 cuota · $30,00')).toBeInTheDocument();
    expect(screen.getByText('28/10')).toBeInTheDocument();
    expect(screen.getByText('AP-7K3M9QRT').closest('a')).toHaveAttribute('href', '/backoffice/apartados/AP-7K3M9QRT');
});
