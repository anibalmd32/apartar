import { GlobalSearch } from '@/components/shell/global-search';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const visit = vi.fn();
const getJson = vi.fn();

vi.mock('@inertiajs/react', () => ({ router: { visit: (...args: unknown[]) => visit(...args) } }));
vi.mock('@/lib/json', () => ({ getJson: (...args: unknown[]) => getJson(...args) }));

beforeEach(() => {
    vi.useFakeTimers();
    visit.mockReset();
    getJson.mockReset();
    getJson.mockResolvedValue({
        groups: [
            { key: 'customers', label: 'Clientes', items: [{ title: 'Ana Pérez', detail: 'V-18.234.567', url: '/backoffice/clientes/7' }] },
            {
                key: 'layaways',
                label: 'Apartados',
                items: [{ title: 'AP-2HJ8K4MN', detail: 'Ana Pérez · Bolso', url: '/backoffice/apartados/AP-2HJ8K4MN' }],
            },
        ],
    });
});

afterEach(() => vi.useRealTimers());

it('Ctrl K abre la búsqueda y Enter va al primer resultado', async () => {
    render(<GlobalSearch />);

    fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
    const input = screen.getByRole('searchbox', { name: 'Buscar cliente, código o producto' });

    fireEvent.change(input, { target: { value: 'Ana' } });
    await act(async () => {
        await vi.advanceTimersByTimeAsync(300);
    });

    expect(getJson).toHaveBeenCalledWith('/backoffice/buscar?q=Ana');
    expect(screen.getByText('AP-2HJ8K4MN')).toBeInTheDocument();

    fireEvent.keyDown(input, { key: 'ArrowDown' });
    fireEvent.keyDown(input, { key: 'Enter' });

    expect(visit).toHaveBeenCalledWith('/backoffice/apartados/AP-2HJ8K4MN');
});

it('con una letra no busca', async () => {
    render(<GlobalSearch />);

    fireEvent.click(screen.getByRole('button', { name: /Buscar cliente, código o producto/ }));
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'a' } });
    await act(async () => {
        await vi.advanceTimersByTimeAsync(300);
    });

    expect(getJson).not.toHaveBeenCalled();
});
