import { ProductInventoryFields } from '@/features/products/components/ProductInventoryFields';
import { type ProductFormData } from '@/features/products/hooks/useProductForm';
import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({ usePage: vi.fn(), router: { reload: vi.fn() } }));

function Inventory({ onStock }: { onStock: (stock: unknown) => void }) {
    const [data, setData] = useState({ stock: 0 } as unknown as ProductFormData);

    const set = (key: string, value: unknown) => {
        onStock(value);
        setData((current) => ({ ...current, [key]: value }) as ProductFormData);
    };

    return <ProductInventoryFields data={data} setData={set as never} errors={{}} saved={null} />;
}

it('las existencias se pueden vaciar y escribir de nuevo sin que quede un cero delante', () => {
    const onStock = vi.fn();
    render(<Inventory onStock={onStock} />);
    const input = screen.getByLabelText('Existencias') as HTMLInputElement;

    fireEvent.change(input, { target: { value: '' } });
    expect(input.value).toBe('');
    expect(onStock).toHaveBeenLastCalledWith(null);

    fireEvent.change(input, { target: { value: `${input.value}5` } });
    expect(input.value).toBe('5');
    expect(onStock).toHaveBeenLastCalledWith(5);
});
