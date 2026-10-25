import { AttentionBell } from '@/components/shell/attention-bell';
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

it('suma comprobantes, chats y la factura vencida', () => {
    props = { teamMember: { unreadChats: 3 }, pastDue: { amount: '$15,00', deadline: null, invoicesUrl: '/backoffice/suscripcion/facturas' } };

    render(<AttentionBell pendingReceipts={12} />);

    fireEvent.click(screen.getByRole('button', { name: 'Pendientes: 16' }));

    expect(screen.getByText('comprobantes por revisar', { exact: false }).closest('a')).toHaveAttribute('href', '/backoffice/comprobantes');
    expect(screen.getByText('conversaciones sin leer', { exact: false }).closest('a')).toHaveAttribute('href', '/backoffice/chat');
    expect(screen.getByText('Tienes una factura vencida ($15,00)').closest('a')).toHaveAttribute('href', '/backoffice/suscripcion/facturas');
});

it('sin pendientes, lo dice', () => {
    props = { teamMember: { unreadChats: null }, pastDue: null };

    render(<AttentionBell pendingReceipts={null} />);

    fireEvent.click(screen.getByRole('button', { name: 'Sin pendientes' }));

    expect(screen.getByText('Nada pendiente. Todo al día.')).toBeInTheDocument();
});
