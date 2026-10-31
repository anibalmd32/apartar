import { FILTER_DEBOUNCE_MS } from '@/features/data-table/hooks/useDebouncedFilter';
import { centsUsd } from '@/lib/money';
import CollectionIndex from '@/pages/backoffice/collections/Index';
import { collectionProps, installmentRow } from '@/test/t065-fixtures';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/backoffice/cobranza', props: { errors: {}, currency: { display: 'usd_primary', rate: { rate: null } } } }),
    router: { get: routerGet, on: () => () => undefined },
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

vi.mock('@/layouts/backoffice-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    routerGet.mockReset();
});

afterEach(() => vi.useRealTimers());

it('pinta la cuota con el cliente, su teléfono y el atraso', () => {
    render(<CollectionIndex {...collectionProps([installmentRow()])} />);

    const row = within(screen.getAllByRole('row')[1]!);
    expect(row.getByText('María González')).toBeInTheDocument();
    expect(row.getByRole('link', { name: '0414-1234567' })).toHaveAttribute('href', 'tel:+584141234567');
    expect(row.getByRole('button', { name: 'Copiar el teléfono de María González' })).toBeInTheDocument();
    expect(row.getByText('5 días de atraso')).toBeInTheDocument();
    expect(row.getByRole('link', { name: 'AP-7K3M9QRT' })).toHaveAttribute('href', '/backoffice/apartados/AP-7K3M9QRT');
});

it('los filtros rápidos van a la URL', () => {
    const { rerender } = render(<CollectionIndex {...collectionProps([installmentRow()])} />);

    fireEvent.click(screen.getByRole('button', { name: 'Vencen hoy' }));
    expect(routerGet.mock.calls[0]?.[1]).toMatchObject({ preset: 'due_today' });

    rerender(<CollectionIndex {...collectionProps([], { preset: 'due_today' }, 'Cuotas sin cubrir que vencen hoy.')} />);
    expect(screen.getByRole('button', { name: 'Vencen hoy' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByText(/Cuotas sin cubrir que vencen hoy\./)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Vencen hoy' }));
    expect(routerGet.mock.calls[1]?.[1]).not.toHaveProperty('preset');
});

it('busca al cliente tras la pausa, no por tecla', async () => {
    render(<CollectionIndex {...collectionProps([installmentRow()])} />);

    fireEvent.change(screen.getByLabelText('Cliente'), { target: { value: 'V-1234' } });
    fireEvent.change(screen.getByLabelText('Cliente'), { target: { value: 'V-12345678' } });
    expect(routerGet).not.toHaveBeenCalled();

    await act(async () => {
        await vi.advanceTimersByTimeAsync(FILTER_DEBOUNCE_MS + 10);
    });

    expect(routerGet).toHaveBeenCalledTimes(1);
    expect(routerGet.mock.calls[0]?.[1]).toMatchObject({ q: 'V-12345678' });
});

it('resume lo que se mira y dice cuántas filas da cada filtro rápido', () => {
    const props = collectionProps([installmentRow()]);
    render(
        <CollectionIndex
            {...props}
            summary={{ installments: 18, pending: { cents: centsUsd(124_000), usd: '$1.240,00', ves: null }, customers: 11 }}
            presetCounts={{ due_today: 6 }}
        />,
    );

    const summary = within(screen.getByLabelText('Resumen'));
    expect(summary.getByText('18')).toBeInTheDocument();
    expect(summary.getByText('$1.240,00')).toBeInTheDocument();
    expect(summary.getByText('Clientes')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Vencen hoy\s*6/ })).toBeInTheDocument();
});

it('avisa de los apartados que esperan la inicial y lleva a su filtro', () => {
    render(
        <CollectionIndex
            {...collectionProps([installmentRow()])}
            awaitingDownPayment={{ count: 2, missing: { cents: centsUsd(8_000), usd: '$80,00', ves: null } }}
        />,
    );

    expect(screen.getByRole('link', { name: /2 apartados esperan la inicial · \$80,00 por cobrar/ })).toHaveAttribute(
        'href',
        '/backoffice/cobranza?preset=awaiting_down_payment',
    );
});

it('el filtro «Esperando inicial» lista el apartado con lo que falta y su plazo', () => {
    const base = collectionProps([], { preset: 'awaiting_down_payment' });
    render(
        <CollectionIndex
            {...base}
            kind="down_payments"
            rows={[
                {
                    id: 3,
                    customer: { name: 'Luis Rojas', phone: '+584141112233', phoneLabel: '0414-1112233' },
                    layaway: { code: 'AP-R8U36BVL', url: '/backoffice/apartados/AP-R8U36BVL' },
                    productName: 'Power Bank',
                    downPayment: { cents: centsUsd(2_500), usd: '$25,00', ves: null },
                    missing: { cents: centsUsd(500), usd: '$5,00', ves: null },
                    expiresAt: null,
                },
            ]}
        />,
    );

    const row = within(screen.getAllByRole('row')[1]!);
    expect(row.getByText('Power Bank')).toBeInTheDocument();
    expect(row.getByText('$5,00')).toBeInTheDocument();
    expect(row.getByText('Sin vencimiento')).toBeInTheDocument();
    expect(row.getByRole('link', { name: 'Registrar pago' })).toHaveAttribute('href', '/backoffice/apartados/AP-R8U36BVL');
});
