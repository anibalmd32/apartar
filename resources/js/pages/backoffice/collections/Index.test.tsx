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
