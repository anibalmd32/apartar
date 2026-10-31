import Pay from '@/pages/portal/Pay';
import { type PayableLayaway } from '@/types/portal';
import { render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { auth: { portal: { mode: 'scoped', fullName: 'María González', businessName: 'Ferretería Oriente', unreadChats: 0 } } },
    }),
    Link: ({ href, children, className, ...rest }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className} {...rest}>
            {children}
        </a>
    ),
}));

vi.mock('@/layouts/portal-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

const money = (usd: string) => ({ cents: '0', usd, ves: null });

function layaway(code: string, productName: string, overrides: Partial<PayableLayaway> = {}): PayableLayaway {
    return {
        code,
        status: 'pending_down_payment',
        statusLabel: 'Esperando la inicial',
        isTerminal: false,
        acceptsPayments: true,
        productName,
        quantity: 1,
        total: money('$70,00'),
        paid: money('$0,00'),
        remaining: money('$70,00'),
        nextDueDate: null,
        nextDueDateLabel: null,
        overdueInstallments: 0,
        nextAmount: money('$25,00'),
        businessName: 'Ferretería Oriente',
        payUrl: `/mi-cuenta/apartados/${code}/comprobante`,
        ...overrides,
    } as PayableLayaway;
}

it('lista los apartados por pagar en el orden que llegan y cada uno lleva a su comprobante', () => {
    render(
        <Pay
            dashboardUrl="/mi-cuenta"
            layaways={[layaway('AP-NUEVO123', 'Mini UPS'), layaway('AP-VIEJO123', 'Nevera', { status: 'active', statusLabel: 'Al día' })]}
        />,
    );

    const items = within(screen.getByRole('list', { name: 'Apartados por pagar' })).getAllByRole('link');

    expect(items.map((item) => item.getAttribute('aria-label'))).toEqual(['Pagar Mini UPS (AP-NUEVO123)', 'Pagar Nevera (AP-VIEJO123)']);
    expect(items[0]).toHaveAttribute('href', '/mi-cuenta/apartados/AP-NUEVO123/comprobante');
    expect(screen.getByText(/Inicial:/)).toBeInTheDocument();
    expect(screen.getByText(/Próximo pago:/)).toBeInTheDocument();
});

it('sin nada por pagar lo dice y manda a sus apartados', () => {
    render(<Pay dashboardUrl="/mi-cuenta" layaways={[]} />);

    expect(screen.getByText('No tienes apartados por pagar ahora mismo.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Ver mis apartados' })).toHaveAttribute('href', '/mi-cuenta');
});
