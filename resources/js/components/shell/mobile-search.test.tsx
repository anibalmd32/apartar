import { MobileSearch } from '@/components/shell/mobile-search';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const visit = vi.fn();
const getJson = vi.fn();

vi.mock('@inertiajs/react', () => ({ router: { visit: (...args: unknown[]) => visit(...args) } }));
vi.mock('@/lib/json', () => ({ getJson: (...args: unknown[]) => getJson(...args) }));

beforeEach(() => {
    visit.mockReset();
    getJson.mockReset();
    getJson.mockResolvedValue({
        groups: [{ key: 'customers', label: 'Clientes', items: [{ title: 'Ana Pérez', detail: 'V-18.234.567', url: '/backoffice/clientes/7' }] }],
    });
});

afterEach(() => vi.useRealTimers());

it('el botón de buscar abre la búsqueda a pantalla completa con el campo enfocado', async () => {
    render(<MobileSearch />);

    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Buscar' }));

    const input = screen.getByRole('searchbox', { name: 'Buscar cliente, código o producto' });
    await waitFor(() => expect(input).toHaveFocus());
    expect(input).toHaveAttribute('enterkeyhint', 'search');
    expect(screen.getByRole('dialog')).toBeInTheDocument();
});

it('busca, abre el resultado elegido y se cierra', async () => {
    vi.useFakeTimers();
    render(<MobileSearch />);

    fireEvent.click(screen.getByRole('button', { name: 'Buscar' }));
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Ana' } });
    await act(async () => {
        await vi.advanceTimersByTimeAsync(300);
    });

    expect(getJson).toHaveBeenCalledWith('/backoffice/buscar?q=Ana');

    fireEvent.click(screen.getByRole('button', { name: /Ana Pérez/ }));

    expect(visit).toHaveBeenCalledWith('/backoffice/clientes/7');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

it('Cancelar cierra la búsqueda y olvida lo escrito', async () => {
    render(<MobileSearch />);

    fireEvent.click(screen.getByRole('button', { name: 'Buscar' }));
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Ana' } });
    fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: 'Buscar' }));

    expect(await screen.findByRole('searchbox')).toHaveValue('');
});
