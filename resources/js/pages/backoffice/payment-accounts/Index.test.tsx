import PaymentAccountsIndex from '@/pages/backoffice/payment-accounts/Index';
import { type PaymentAccountView } from '@/types/payment';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/backoffice/cuentas-de-cobro', props: { planState: null, teamMember: null, errors: {} } }),
    router: { put: vi.fn() },
}));

vi.mock('@/layouts/backoffice-layout', () => ({
    default: ({ children, actions }: { children: ReactNode; actions?: ReactNode }) => (
        <main>
            {actions}
            {children}
        </main>
    ),
}));

vi.mock('@/features/tenancy/components/PaymentAccountForm', () => ({
    PaymentAccountForm: ({ account }: { account?: PaymentAccountView }) => <form aria-label={account ? 'Editar' : 'Nueva'} />,
}));

const account: PaymentAccountView = {
    id: 1,
    method: 'pago_movil',
    methodLabel: 'Pago móvil',
    identifierLabel: 'Teléfono',
    bank: 'Banco de Venezuela',
    bankCode: '0102',
    holderName: 'Valentina Rojas',
    holderDocument: 'J412345678',
    identifier: '04141234567',
    instructions: null,
    isActive: true,
    position: 1,
};

it('en el celular «Añadir cuenta» va arriba y abre el formulario en una hoja', () => {
    render(<PaymentAccountsIndex accounts={[account]} methods={[]} usage={{ used: 1, limit: 5, percentage: 20, isAtLimit: false }} />);

    const add = screen.getByRole('button', { name: 'Añadir cuenta' });
    expect(add).toHaveClass('lg:hidden');
    expect(screen.getByText('Nueva cuenta de cobro').closest('section')).toHaveClass('max-lg:hidden');

    fireEvent.click(add);

    expect(within(screen.getByRole('dialog')).getByRole('form', { name: 'Nueva' })).toBeInTheDocument();
});

it('el nombre del banco no se corta y cada botón de copiar mide 44 px en el celular', () => {
    render(<PaymentAccountsIndex accounts={[account]} methods={[]} usage={{ used: 1, limit: 5, percentage: 20, isAtLimit: false }} />);

    expect(screen.getAllByText('Banco de Venezuela').every((element) => !element.classList.contains('truncate'))).toBe(true);
    expect(screen.getByRole('button', { name: 'Copiar teléfono del pago móvil' })).toHaveClass('max-lg:size-11');
});
