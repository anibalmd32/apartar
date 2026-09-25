import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { type ProductFilters, type ProductStatusDefinition, type TaxonomyOption } from '@/types/product';
import { useEffect, useState } from 'react';

/**
 * Los filtros del inventario (`REQ-BOF-020` RB-9): búsqueda por nombre y SKU,
 * estado, categoría, etiqueta y stock bajo.
 *
 * Pintan lo que el servidor **aplicó** (`meta.filters`), no lo que se pidió: un
 * filtro ignorado vuelve vacío, y el aviso de por qué lo pone la tabla
 * (`REQ-BOF-045` SP-4). Cambiar uno es cambiar la query string, y eso lo hace
 * `useServerTable`.
 *
 * La búsqueda espera a que se deje de escribir, como la del catálogo
 * (`REQ-CAT-002` RB-6): una petición por tecla sería una petición por tecla.
 */

interface InventoryFiltersProps {
    filters: ProductFilters;
    statuses: readonly ProductStatusDefinition[];
    categories: readonly TaxonomyOption[];
    tags: readonly TaxonomyOption[];
    onChange: (key: keyof ProductFilters, value: string | null) => void;
}

/** Nombres de los filtros para el aviso de la tabla. */
export const INVENTORY_FILTER_LABELS: Readonly<Record<keyof ProductFilters, string>> = {
    status: 'estado',
    category: 'categoría',
    tag: 'etiqueta',
    low_stock: 'stock bajo',
    q: 'búsqueda',
};

const DEBOUNCE_MS = 300;

function options(all: string, items: readonly TaxonomyOption[]) {
    return [{ value: '', label: all }, ...items.map((item) => ({ value: String(item.id), label: item.name }))];
}

export function InventoryFilters({ filters, statuses, categories, tags, onChange }: InventoryFiltersProps) {
    const [search, setSearch] = useState(filters.q ?? '');

    useEffect(() => {
        if (search === (filters.q ?? '')) {
            return;
        }

        const timer = window.setTimeout(() => onChange('q', search === '' ? null : search), DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [search, filters.q, onChange]);

    const pick = (key: keyof ProductFilters) => (event: { target: { value: string } }) =>
        onChange(key, event.target.value === '' ? null : event.target.value);

    return (
        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <TextField
                label="Buscar"
                name="q"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                hint="Por nombre o por SKU."
                autoComplete="off"
            />
            <SelectField
                label="Estado"
                name="status"
                options={[{ value: '', label: 'Todos' }, ...statuses.map((status) => ({ value: status.value, label: status.label }))]}
                value={filters.status ?? ''}
                onChange={pick('status')}
            />
            <SelectField
                label="Categoría"
                name="category"
                options={options('Todas', categories)}
                value={filters.category ?? ''}
                onChange={pick('category')}
            />
            <SelectField label="Etiqueta" name="tag" options={options('Todas', tags)} value={filters.tag ?? ''} onChange={pick('tag')} />

            <label className="label cursor-pointer gap-2 sm:col-span-2 lg:col-span-4">
                <input
                    type="checkbox"
                    className="checkbox checkbox-sm"
                    checked={filters.low_stock === '1'}
                    onChange={(event) => onChange('low_stock', event.target.checked ? '1' : null)}
                />
                <span className="label-text">Solo los que se están acabando</span>
            </label>
        </div>
    );
}
