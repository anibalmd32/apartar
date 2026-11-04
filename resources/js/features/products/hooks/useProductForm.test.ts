import { useProductForm } from '@/features/products/hooks/useProductForm';
import { renderHook } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    router: { on: vi.fn(() => () => undefined) },
    useForm: (initial: Record<string, unknown>) => ({ data: initial, setData: vi.fn(), errors: {}, transform: vi.fn(), post: vi.fn(), put: vi.fn() }),
}));

it('un producto nuevo arranca con una unidad en existencia, para no publicarse agotado sin querer', () => {
    const { result } = renderHook(() => useProductForm({ installmentsCount: 4, frequencyDays: 15 }));

    expect(result.current.data.stock).toBe(1);
});

it('un producto que ya existe conserva sus existencias, también si son cero', () => {
    const { result } = renderHook(() =>
        useProductForm({ installmentsCount: 4, frequencyDays: 15 }, {
            stock: 0,
            tagIds: [],
            tags: [],
            specifications: [],
            images: [],
        } as unknown as Parameters<typeof useProductForm>[1]),
    );

    expect(result.current.data.stock).toBe(0);
});
