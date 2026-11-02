import CategoriesIndex from '@/pages/backoffice/taxonomy/Categories';
import { type CategoryView } from '@/types/taxonomy';
import { render, screen, within } from '@testing-library/react';
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
    router: { patch: vi.fn(), visit: vi.fn(), delete: vi.fn() },
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

it('editar y eliminar se leen con texto en la tarjeta, no solo con un icono', () => {
    render(<CategoriesIndex categories={[category(1, 'Vestidos')]} />);

    expect(screen.getByRole('button', { name: 'Editar Vestidos' })).toHaveTextContent('Editar');
    expect(screen.getByRole('button', { name: 'Eliminar Vestidos' })).toHaveTextContent('Eliminar');
});
