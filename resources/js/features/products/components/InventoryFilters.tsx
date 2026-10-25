import { type FilterTab, FilterTabs } from '@/components/ui/filter-tabs';
import { Icon } from '@/components/ui/icon';
import { SelectField } from '@/components/ui/select-field';
import { InventorySortSelect } from '@/features/products/components/InventorySortSelect';
import { type ProductFilters, type ProductStatusDefinition, type TaxonomyOption } from '@/types/product';
import { cilSearch } from '@coreui/icons';
import { useEffect, useState } from 'react';

interface InventoryFiltersProps {
    filters: ProductFilters;
    statuses: readonly ProductStatusDefinition[];
    categories: readonly TaxonomyOption[];
    tags: readonly TaxonomyOption[];
    onChange: (key: keyof ProductFilters, value: string | null) => void;
    sort?: string | null;
    onSort?: (sort: string | null) => void;
    counts?: Readonly<Record<string, number>>;
    onChangeMany?: (patch: Partial<Record<keyof ProductFilters, string | null>>) => void;
}

export const INVENTORY_FILTER_LABELS: Readonly<Record<keyof ProductFilters, string>> = {
    status: 'estado',
    category: 'categoría',
    tag: 'etiqueta',
    low_stock: 'stock bajo',
    q: 'búsqueda',
    scheduled: 'programados',
};

const STATUS_TAB_LABELS: Readonly<Record<string, string>> = {
    draft: 'Borradores',
    published: 'Publicados',
    archived: 'Archivados',
};

const DEBOUNCE_MS = 300;

function options(all: string, items: readonly TaxonomyOption[]) {
    return [{ value: '', label: all }, ...items.map((item) => ({ value: String(item.id), label: item.name }))];
}

export function InventoryFilters({
    filters,
    statuses,
    categories,
    tags,
    onChange,
    sort = null,
    onSort,
    counts = {},
    onChangeMany,
}: InventoryFiltersProps) {
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

    const tabs: FilterTab<string | null>[] = [
        { value: null, label: 'Todos', count: counts.all },
        ...statuses.map((status) => ({ value: status.value, label: STATUS_TAB_LABELS[status.value] ?? status.label, count: counts[status.value] })),
        ...(onChangeMany ? [{ value: 'scheduled', label: 'Programados', count: counts.scheduled }] : []),
    ];
    const activeTab = filters.scheduled === '1' ? 'scheduled' : filters.status;
    const pickTab = (value: string | null) =>
        onChangeMany
            ? onChangeMany(value === 'scheduled' ? { status: null, scheduled: '1' } : { status: value, scheduled: null })
            : onChange('status', value);

    return (
        <div className="flex flex-col gap-4">
            <FilterTabs label="Estado" tabs={tabs} value={activeTab} onChange={pickTab} />

            <div className="flex flex-wrap items-center gap-2">
                <label className="input w-full gap-2.5 px-3 sm:w-[300px]">
                    <Icon icon={cilSearch} className="text-ink-3" />
                    <input
                        type="search"
                        name="q"
                        className="grow"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Buscar por nombre o SKU"
                        aria-label="Buscar por nombre o SKU"
                        autoComplete="off"
                    />
                </label>
                <SelectField
                    label="Categoría"
                    hideLabel
                    inline
                    name="category"
                    options={options('Categoría', categories)}
                    value={filters.category ?? ''}
                    onChange={pick('category')}
                />
                <SelectField
                    label="Etiqueta"
                    hideLabel
                    inline
                    name="tag"
                    options={options('Etiqueta', tags)}
                    value={filters.tag ?? ''}
                    onChange={pick('tag')}
                />
                {onSort ? <InventorySortSelect sort={sort} onChange={onSort} /> : null}

                <label className="text-ink-2 flex cursor-pointer items-center gap-2.5 text-[13px] font-medium lg:ml-auto">
                    <input
                        type="checkbox"
                        className="toggle toggle-sm"
                        checked={filters.low_stock === '1'}
                        onChange={(event) => onChange('low_stock', event.target.checked ? '1' : null)}
                    />
                    Solo los que se están acabando
                </label>
            </div>
        </div>
    );
}
