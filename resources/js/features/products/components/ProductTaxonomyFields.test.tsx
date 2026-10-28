import { ProductTaxonomyFields } from '@/features/products/components/ProductTaxonomyFields';
import { type ProductFormData } from '@/features/products/hooks/useProductForm';
import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { expect, it } from 'vitest';

let latest: ProductFormData | null = null;

const EMPTY = {
    name: '',
    sku: '',
    slug: '',
    description: '',
    price: '',
    compare_at_price: '',
    down_payment: '0.00',
    installments_count: 4,
    frequency_days: 15,
    stock: 0,
    category_id: '',
    category_name: '',
    tag_ids: [],
    tag_names: [],
} as unknown as ProductFormData;

function Harness({ initial = EMPTY }: { initial?: ProductFormData }) {
    const [data, setState] = useState<ProductFormData>(initial);
    latest = data;

    const setData = ((key: unknown, value?: unknown) => {
        setState((current) =>
            typeof key === 'function' ? key(current) : typeof key === 'string' ? { ...current, [key]: value } : { ...current, ...(key as object) },
        );
    }) as never;

    return <ProductTaxonomyFields data={data} setData={setData} errors={{}} categories={[]} tags={[]} />;
}

it('deja escribir comas para separar las etiquetas nuevas', () => {
    render(<Harness />);

    const input = screen.getByLabelText('Etiquetas nuevas');

    fireEvent.change(input, { target: { value: 'viaje,' } });
    expect(input).toHaveValue('viaje,');

    fireEvent.change(input, { target: { value: 'viaje, impermeable' } });
    expect(input).toHaveValue('viaje, impermeable');
    expect(latest?.tag_names).toEqual(['viaje', 'impermeable']);
});
