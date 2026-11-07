import CategoriesIndex from '@/pages/backoffice/taxonomy/Categories';
import { type CategoryView } from '@/types/taxonomy';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/backoffice/categorias', props: { errors: {} } }),
    useForm: (initial: Record<string, string>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
        clearErrors: vi.fn(),
    }),
    router: { patch: vi.fn(), visit: vi.fn(), delete: vi.fn(), on: vi.fn(() => () => undefined) },
    Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
}));

vi.mock('@/layouts/backoffice-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

const category = (id: number, name: string, productsCount = 2): CategoryView => ({
    id,
    name,
    slug: name.toLowerCase(),
    position: id,
    productsCount,
});

it('en el celular las categorías se apilan como tarjetas sin ancho mínimo que obligue a desplazar', () => {
    render(<CategoriesIndex categories={[category(1, 'Vestidos'), category(2, 'Calzado')]} />);

    const table = within(screen.getByRole('region', { name: 'Tus categorías' })).getByRole('table');

    expect(table.parentElement).toHaveClass('table-stack');
    expect(table).not.toHaveClass('min-w-[640px]');

    const [, row] = within(table).getAllByRole('row');
    const cells = within(row!).getAllByRole('cell');

    expect(cells.map((cell) => cell.getAttribute('data-cell'))).toEqual(['shrink', 'primary', 'shrink', 'actions']);
});

it('editar y eliminar son solo iconos en la tarjeta y su nombre queda para el lector de pantalla', () => {
    render(<CategoriesIndex categories={[category(1, 'Vestidos')]} />);

    for (const name of ['Editar Vestidos', 'Eliminar Vestidos']) {
        const button = screen.getByRole('button', { name });

        expect(button).toHaveClass('btn-square');
        expect(button.querySelector('span')).toHaveClass('sr-only');
    }
});

it('en el celular se ordena con flechas y el orden se guarda desde la lista', async () => {
    const { router } = await import('@inertiajs/react');

    render(<CategoriesIndex categories={[category(1, 'Vestidos'), category(2, 'Calzado'), category(3, 'Bolsos')]} />);

    const save = screen.getByRole('button', { name: 'Guardar orden' });

    expect(save).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Subir Vestidos' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Bajar Bolsos' })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'Subir Bolsos' }));

    expect(save).toBeEnabled();

    fireEvent.click(save);

    expect(router.patch).toHaveBeenCalledWith('/backoffice/categorias/orden', { categories: [1, 3, 2] }, expect.anything());
});

it('al añadir una categoría el enlace queda detrás de «Cambiar el enlace», igual que al editar', () => {
    render(<CategoriesIndex categories={[category(1, 'Vestidos')]} />);

    expect(screen.queryByLabelText('Enlace')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cambiar el enlace' })).toBeInTheDocument();
});
