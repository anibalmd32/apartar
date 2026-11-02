import { InventoryFilters } from '@/features/products/components/InventoryFilters';
import { type ProductFilters } from '@/types/product';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

const FILTERS: ProductFilters = { status: null, category: null, tag: null, low_stock: null, q: null, scheduled: null };

it('la hoja de filtros aplica la categoría y el orden en una sola petición', () => {
    const onChangeMany = vi.fn();
    const onSort = vi.fn();

    render(
        <InventoryFilters
            filters={FILTERS}
            statuses={[{ value: 'draft', label: 'Borrador' }]}
            categories={[{ id: 3, name: 'Accesorios' }]}
            tags={[]}
            onChange={vi.fn()}
            onChangeMany={onChangeMany}
            sort="-created"
            onSort={onSort}
        />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Filtros' }));
    const sheet = within(screen.getByRole('dialog', { name: 'Filtros' }));

    expect(sheet.getByLabelText('Ordenar')).toHaveDisplayValue('Recientes');

    fireEvent.change(sheet.getByLabelText('Categoría'), { target: { value: '3' } });
    fireEvent.change(sheet.getByLabelText('Ordenar'), { target: { value: 'name' } });
    fireEvent.click(sheet.getByRole('button', { name: 'Aplicar' }));

    expect(onChangeMany).toHaveBeenCalledExactlyOnceWith({ category: '3' }, 'name');
    expect(onSort).not.toHaveBeenCalled();
});

it('los filtros rápidos de estado son píldoras como en Clientes y Cobranza', () => {
    render(
        <InventoryFilters
            filters={FILTERS}
            statuses={[{ value: 'draft', label: 'Borrador' }]}
            categories={[]}
            tags={[]}
            onChange={vi.fn()}
            onChangeMany={vi.fn()}
        />,
    );

    const pills = within(screen.getByRole('group', { name: 'Estado' })).getAllByRole('button');

    expect(pills.every((pill) => pill.classList.contains('rounded-full'))).toBe(true);
    expect(pills[0]).toHaveAttribute('aria-pressed', 'true');
});
