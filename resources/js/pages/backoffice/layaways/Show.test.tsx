import { centsUsd } from '@/lib/money';
import LayawayShow from '@/pages/backoffice/layaways/Show';
import { type BackofficeLayawayShowProps } from '@/types/layaway-backoffice';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

const { useForm, usePage, post, patch, setData } = vi.hoisted(() => ({
    useForm: vi.fn(),
    usePage: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    setData: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({ useForm, usePage }));
vi.mock('@/layouts/backoffice-layout', () => ({
    default: ({ children, actions }: { children: ReactNode; actions?: ReactNode }) => (
        <main>
            {actions}
            {children}
        </main>
    ),
}));

const money = (usd: string, cents: number) => ({ cents: centsUsd(cents), usd, ves: null });

function props(layaway: Partial<BackofficeLayawayShowProps['layaway']> = {}): BackofficeLayawayShowProps {
    return {
        layaway: {
            code: 'AP-7K3M9QRT',
            status: 'active',
            statusLabel: 'Activo',
            productName: 'Nevera Mabe',
            quantity: 1,
            total: money('$200,00', 20_000),
            downPayment: money('$50,00', 5_000),
            paid: money('$110,00', 11_000),
            remaining: money('$90,00', 9_000),
            createdAt: '10 de septiembre de 2026, 10:00 a. m.',
            cancelledAt: null,
            deliveredAt: null,
            cancellationReason: null,
            cancelUrl: '/backoffice/apartados/AP-7K3M9QRT/cancelar',
            deliverUrl: null,
            ...layaway,
        },
        customer: { fullName: 'María González', documentNumber: 'V12345678', phone: '+584141234567' },
        installments: [
            {
                number: 1,
                dueDate: '2026-09-25',
                dueDateLabel: '25 de septiembre de 2026',
                amount: money('$30,00', 3_000),
                paid: money('$30,00', 3_000),
                statusLabel: 'Pagada',
                reschedule: null,
            },
            {
                number: 2,
                dueDate: '2026-10-10',
                dueDateLabel: '10 de octubre de 2026',
                amount: money('$30,00', 3_000),
                paid: money('$0,00', 0),
                statusLabel: 'Pendiente',
                reschedule: {
                    url: '/backoffice/cuotas/12/reprogramar',
                    earliest: '2026-10-04',
                    latest: '2026-10-25',
                    window: 'entre el 4 de octubre de 2026 y el 25 de octubre de 2026',
                },
            },
        ],
        payments: [],
        manualPayment: null,
        reallocation: null,
    };
}

beforeEach(() => {
    post.mockReset();
    patch.mockReset();
    usePage.mockReturnValue({ props: { errors: {}, currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: { rate: null } } } });
    useForm.mockImplementation((initial: Record<string, string>) => ({
        data: initial,
        setData,
        post,
        patch,
        processing: false,
        errors: {},
        reset: vi.fn(),
        clearErrors: vi.fn(),
    }));
});

it('pide reconocer lo pagado antes de cancelar un apartado con abonos', () => {
    render(<LayawayShow {...props()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Cancelar apartado' }));

    const dialog = screen.getByRole('dialog', { name: 'Cancelar este apartado' });
    const confirm = within(dialog).getByRole('button', { name: 'Cancelar apartado' });

    expect(dialog).toHaveTextContent('El cliente ha pagado $110,00');
    expect(confirm).toBeDisabled();

    fireEvent.click(within(dialog).getByRole('checkbox'));
    fireEvent.click(confirm);

    expect(post).toHaveBeenCalledWith('/backoffice/apartados/AP-7K3M9QRT/cancelar', expect.anything());
});

it('sin pagos, cancela sin confirmación reforzada', () => {
    render(<LayawayShow {...props({ paid: money('$0,00', 0) })} />);

    fireEvent.click(screen.getByRole('button', { name: 'Cancelar apartado' }));

    const dialog = screen.getByRole('dialog', { name: 'Cancelar este apartado' });

    expect(within(dialog).queryByRole('checkbox')).toBeNull();
    expect(within(dialog).getByRole('button', { name: 'Cancelar apartado' })).toBeEnabled();
});

it('enseña el motivo de la cancelación al negocio y ya no ofrece cancelar', () => {
    render(
        <LayawayShow
            {...props({
                status: 'cancelled',
                statusLabel: 'Cancelado',
                cancelUrl: null,
                cancelledAt: '1 de octubre de 2026',
                cancellationReason: 'No volvió a pagar.',
            })}
        />,
    );

    expect(screen.getByText(/Motivo: No volvió a pagar\./)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Cancelar apartado' })).toBeNull();
});

it('ofrece marcar entregado solo cuando el servidor lo permite', () => {
    const { rerender } = render(<LayawayShow {...props()} />);

    expect(screen.queryByRole('button', { name: 'Marcar entregado' })).toBeNull();

    rerender(<LayawayShow {...props({ status: 'completed', cancelUrl: null, deliverUrl: '/backoffice/apartados/AP-7K3M9QRT/entregar' })} />);
    fireEvent.click(screen.getByRole('button', { name: 'Marcar entregado' }));

    expect(post).toHaveBeenCalledWith('/backoffice/apartados/AP-7K3M9QRT/entregar', expect.anything());
});

it('reprograma solo cuotas sin pagar, dentro de su ventana', () => {
    render(<LayawayShow {...props()} />);

    expect(screen.queryByRole('button', { name: 'Reprogramar la cuota 1' })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Reprogramar la cuota 2' }));

    const dialog = screen.getByRole('dialog', { name: 'Reprogramar la cuota 2' });
    fireEvent.click(within(dialog).getByLabelText('Nueva fecha'));

    expect(screen.getByRole('gridcell', { name: / 3 de octubre de 2026/ })).toBeDisabled();
    expect(screen.getByRole('gridcell', { name: / 4 de octubre de 2026/ })).toBeEnabled();
    expect(screen.getByRole('gridcell', { name: / 25 de octubre de 2026/ })).toBeEnabled();
    expect(screen.getByRole('gridcell', { name: / 26 de octubre de 2026/ })).toBeDisabled();
    expect(dialog).toHaveTextContent('entre el 4 de octubre de 2026 y el 25 de octubre de 2026');

    fireEvent.click(within(dialog).getByRole('button', { name: 'Reprogramar' }));

    expect(patch).toHaveBeenCalledWith('/backoffice/cuotas/12/reprogramar', expect.anything());
});

it('ofrece reasignar un pago solo cuando hay reparto que editar', () => {
    const { unmount } = render(<LayawayShow {...props()} />);
    expect(screen.queryByRole('form', { name: 'Reasignar un pago' })).not.toBeInTheDocument();
    unmount();

    render(
        <LayawayShow
            {...props()}
            reallocation={{
                installments: [{ id: 11, number: 1, amountCents: 3_000, paidCents: 3_000 }],
                payments: [
                    {
                        id: 7,
                        url: '/backoffice/pagos/7/reasignar',
                        label: '$30,00 del 10 de septiembre',
                        allocations: [{ installmentId: 11, cents: 3_000 }],
                    },
                ],
            }}
        />,
    );
    expect(screen.getByRole('form', { name: 'Reasignar un pago' })).toBeInTheDocument();
    expect(screen.getByLabelText('Importe para la cuota 1')).toHaveValue('30.00');
});
