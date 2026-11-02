import { FilterBar } from '@/components/ui/filter-bar';
import { FilterTabs, type FilterTab } from '@/components/ui/filter-tabs';
import { SearchField } from '@/components/ui/search-field';
import { InventoryFilterFields, inventoryChipLabels, type InventorySheetValues } from '@/features/products/components/InventoryFilterFields';
import { useFilterDraft } from '@/hooks/use-filter-draft';
import { type ProductFilters, type ProductStatusDefinition, type TaxonomyOption } from '@/types/product';
import { useEffect, useState } from 'react';

type FilterPatch = Partial<Record<keyof ProductFilters, string | null>>;

interface InventoryFiltersProps {
    filters: ProductFilters;
    statuses: readonly ProductStatusDefinition[];
    categories: readonly TaxonomyOption[];
    tags: readonly TaxonomyOption[];
    onChange: (key: keyof ProductFilters, value: string | null) => void;
    onChangeMany: (patch: FilterPatch, sort?: string | null) => void;
    sort?: string | null;
    onSort?: (sort: string | null) => void;
    counts?: Readonly<Record<string, number>>;
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

export function InventoryFilters({
    filters,
    statuses,
    categories,
    tags,
    onChange,
    onChangeMany,
    sort = null,
    onSort,
    counts = {},
}: InventoryFiltersProps) {
    const [search, setSearch] = useState(filters.q ?? '');
    const applied: InventorySheetValues = { category: filters.category, tag: filters.tag, low_stock: filters.low_stock, sort };
    const sheet = useFilterDraft(applied);

    useEffect(() => {
        if (search === (filters.q ?? '')) {
            return;
        }

        const timer = window.setTimeout(() => onChange('q', search === '' ? null : search), DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [search, filters.q, onChange]);

    const tabs: FilterTab<string | null>[] = [
        { value: null, label: 'Todos', count: counts.all },
        ...statuses.map((status) => ({ value: status.value, label: STATUS_TAB_LABELS[status.value] ?? status.label, count: counts[status.value] })),
        { value: 'scheduled', label: 'Programados', count: counts.scheduled },
    ];
    const activeTab = filters.scheduled === '1' ? 'scheduled' : filters.status;
    const pickTab = (value: string | null) =>
        onChangeMany(value === 'scheduled' ? { status: null, scheduled: '1' } : { status: value, scheduled: null });

    const apply = () => {
        const { sort: nextSort, ...patch } = sheet.changes();
        onChangeMany(patch, nextSort);
    };

    return (
        <div className="flex flex-col gap-3 lg:gap-4">
            <FilterTabs label="Estado" tabs={tabs} value={activeTab} onChange={pickTab} />

            <FilterBar
                search={
                    <SearchField
                        label="Buscar por nombre o SKU"
                        name="q"
                        className="lg:w-[300px]"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Nombre o SKU"
                    />
                }
                inline={
                    <InventoryFilterFields
                        values={applied}
                        onPick={(key, value) => (key === 'sort' ? onSort?.(value) : onChange(key, value))}
                        categories={categories}
                        tags={tags}
                        sortable={onSort !== undefined}
                    />
                }
                sheet={
                    <InventoryFilterFields
                        values={sheet.draft}
                        onPick={sheet.set}
                        categories={categories}
                        tags={tags}
                        sortable={onSort !== undefined}
                        stacked
                    />
                }
                chips={inventoryChipLabels(applied, categories, tags).map((chip) => ({ ...chip, onRemove: () => onChange(chip.key, null) }))}
                onOpen={sheet.reset}
                onApply={apply}
                onClear={() => onChangeMany({ category: null, tag: null, low_stock: null })}
            />
        </div>
    );
}
