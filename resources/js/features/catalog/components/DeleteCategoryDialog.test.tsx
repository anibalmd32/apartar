import { DeleteCategoryDialog } from '@/features/catalog/components/DeleteCategoryDialog';
import { type CategoryView } from '@/types/taxonomy';
import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({ router: { post: vi.fn(), delete: vi.fn() } }));

const category = { id: 3, name: 'Accesorios', slug: 'accesorios', productsCount: 8 } as CategoryView;
const others = [{ id: 5, name: 'Bolsos', slug: 'bolsos', productsCount: 2 }] as CategoryView[];

beforeEach(() => vi.mocked(router.post).mockReset());

it('mueve los productos y después elimina la categoría', () => {
    const onClose = vi.fn();
    render(<DeleteCategoryDialog category={category} others={others} open onClose={onClose} />);

    expect(screen.getByText('Esta categoría tiene 8 productos. Elige a cuál moverlos antes de eliminarla.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Mover y eliminar' })).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Mover productos a'), { target: { value: '5' } });
    fireEvent.click(screen.getByRole('button', { name: 'Mover y eliminar' }));

    expect(router.post).toHaveBeenCalledWith('/backoffice/categorias/3/reasignar', { category_id: 5 }, expect.any(Object));
    expect(router.delete).not.toHaveBeenCalled();

    const options = vi.mocked(router.post).mock.calls[0]?.[2] as { onSuccess: () => void };
    options.onSuccess();

    expect(router.delete).toHaveBeenCalledWith('/backoffice/categorias/3', { preserveScroll: true });
    expect(onClose).toHaveBeenCalled();
});

it('«Solo mover» reasigna sin eliminar', () => {
    vi.mocked(router.delete).mockReset();
    render(<DeleteCategoryDialog category={category} others={others} open onClose={vi.fn()} />);

    fireEvent.change(screen.getByLabelText('Mover productos a'), { target: { value: '5' } });
    fireEvent.click(screen.getByRole('button', { name: 'Solo mover' }));

    const options = vi.mocked(router.post).mock.calls[0]?.[2] as { onSuccess: () => void };
    options.onSuccess();

    expect(router.delete).not.toHaveBeenCalled();
});
