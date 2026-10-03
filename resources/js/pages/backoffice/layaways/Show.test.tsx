import { centsUsd } from '@/lib/money';
import LayawayShow from '@/pages/backoffice/layaways/Show';
import { type BackofficeLayawayShowProps } from '@/types/layaway-backoffice';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

/**
 * Un apartado visto por el negocio (`REQ-LAY-009`, `REQ-LAY-010`,
 * `REQ-LAY-011`).
 */

const { useForm, usePage, post, patch, setData } = vi.hoisted(() => ({
    useForm: vi.fn(),
    usePage: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    setData: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({ useForm, usePage }));
vi.mock('@/layouts/backoffice-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

const money = (usd: string, cents: number) => ({ cents: centsUsd(cents), usd, ves: null });

function props(layaway: Partial<BackofficeLayawayShowProps['layaway']> = {}): BackofficeLayawayShowProps {
    return {
        layaway: {
            code: 'AP-7K3M9QRT',
            status: 'active',
            statusLabel: 'Activo',
            productName: 'Nevera Mabe',
            quantity: 1,
            total: money('$200.00', 20_000),
            downPayment: money('$50.00', 5_000),
            paid: money('$110.00', 11_000),
            remaining: money('$90.00', 9_000),
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
                amount: money('$30.00', 3_000),
                paid: money('$30.00', 3_000),
                statusLabel: 'Pagada',
                reschedule: null,
            },
            {
                number: 2,
                dueDate: '2026-10-10',
                dueDateLabel: '10 de octubre de 2026',
                amount: money('$30.00', 3_000),
                paid: money('$0.00', 0),
                statusLabel: 'Pendiente',
                reschedule: {
                    url: '/backoffice/cuotas/12/reprogramar',
                    earliest: '2026-10-04',
                    latest: '2026-10-25',
                    window: 'entre el 4 de octubre de 2026 y el 25 de octubre de 2026',
                },
            },
        ],
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

/** `REQ-LAY-009` RB-7 · con abonos, no se confirma sin haber leído cuánto pagó el cliente. */
it('pide reconocer lo pagado antes de cancelar un apartado con abonos', () => {
    render(<LayawayShow {...props()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Cancelar apartado' }));

    const dialog = screen.getByRole('dialog', { name: 'Cancelar este apartado' });
    const confirm = within(dialog).getByRole('button', { name: 'Cancelar apartado' });

    expect(dialog).toHaveTextContent('El cliente ha pagado $110.00');
    expect(confirm).toBeDisabled();

    fireEvent.click(within(dialog).getByRole('checkbox'));
    fireEvent.click(confirm);

    expect(post).toHaveBeenCalledWith('/backoffice/apartados/AP-7K3M9QRT/cancelar', expect.anything());
});

/** `REQ-LAY-009` SP-6 · sin pagos, basta el motivo. */
it('sin pagos, cancela sin confirmación reforzada', () => {
    render(<LayawayShow {...props({ paid: money('$0.00', 0) })} />);

    fireEvent.click(screen.getByRole('button', { name: 'Cancelar apartado' }));

    const dialog = screen.getByRole('dialog', { name: 'Cancelar este apartado' });

    expect(within(dialog).queryByRole('checkbox')).toBeNull();
    expect(within(dialog).getByRole('button', { name: 'Cancelar apartado' })).toBeEnabled();
});

/** `REQ-LAY-009` RB-8 · el negocio ve su motivo íntegro, y un terminal no ofrece cancelar. */
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

/** `REQ-LAY-010` · solo un pagado sin entregar ofrece la entrega. */
it('ofrece marcar entregado solo cuando el servidor lo permite', () => {
    const { rerender } = render(<LayawayShow {...props()} />);

    expect(screen.queryByRole('button', { name: 'Marcar entregado' })).toBeNull();

    rerender(<LayawayShow {...props({ status: 'completed', cancelUrl: null, deliverUrl: '/backoffice/apartados/AP-7K3M9QRT/entregar' })} />);
    fireEvent.click(screen.getByRole('button', { name: 'Marcar entregado' }));

    expect(post).toHaveBeenCalledWith('/backoffice/apartados/AP-7K3M9QRT/entregar', expect.anything());
});

/** `REQ-LAY-011` RB-2 y RB-3 · solo las cuotas sin pagar, y con la fecha acotada a su ventana. */
it('reprograma solo cuotas sin pagar, dentro de su ventana', () => {
    render(<LayawayShow {...props()} />);

    expect(screen.queryByRole('button', { name: 'Reprogramar la cuota 1' })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Reprogramar la cuota 2' }));

    const dialog = screen.getByRole('dialog', { name: 'Reprogramar la cuota 2' });
    const date = within(dialog).getByLabelText('Nueva fecha');

    expect(date).toHaveAttribute('min', '2026-10-04');
    expect(date).toHaveAttribute('max', '2026-10-25');
    expect(dialog).toHaveTextContent('entre el 4 de octubre de 2026 y el 25 de octubre de 2026');

    fireEvent.click(within(dialog).getByRole('button', { name: 'Reprogramar' }));

    expect(patch).toHaveBeenCalledWith('/backoffice/cuotas/12/reprogramar', expect.anything());
});
