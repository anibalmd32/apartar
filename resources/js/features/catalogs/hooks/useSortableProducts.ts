import { type CatalogProductOption } from '@/types/catalog';
import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface SortableProductsOptions {
    catalogId: number;
    initial: readonly CatalogProductOption[];
}

export function useSortableProducts({ catalogId, initial }: SortableProductsOptions) {
    const [chosen, setChosen] = useState<CatalogProductOption[]>(() => [...initial]);
    const [saving, setSaving] = useState(false);

    const savedIds = useMemo(() => initial.map((product) => product.id), [initial]);

    const ids = chosen.map((product) => product.id);

    const isDirty = ids.length !== savedIds.length || ids.some((id, index) => id !== savedIds[index]);

    const add = (product: CatalogProductOption) => {
        setChosen((current) => (current.some((row) => row.id === product.id) ? current : [...current, product]));
    };

    const remove = (id: number) => {
        setChosen((current) => current.filter((product) => product.id !== id));
    };

    const reorder = (from: number, to: number) => {
        setChosen((current) => {
            const next = [...current];
            const [moved] = next.splice(from, 1);

            if (moved === undefined) {
                return current;
            }

            next.splice(to, 0, moved);

            return next;
        });
    };

    const save = () => {
        setSaving(true);

        router.patch(`/backoffice/catalogos/${catalogId}/productos`, { products: ids }, { preserveScroll: true, onFinish: () => setSaving(false) });
    };

    return { chosen, ids, isDirty, saving, add, remove, reorder, save };
}
