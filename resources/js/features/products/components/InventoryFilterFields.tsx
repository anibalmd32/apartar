import { SelectField } from '@/components/ui/select-field';
import { ToggleField } from '@/components/ui/toggle-field';
import { InventorySortSelect } from '@/features/products/components/InventorySortSelect';
import { flagChip, optionChip, present } from '@/lib/filter-chips';
import { type TaxonomyOption } from '@/types/product';

export interface InventorySheetValues {
    category: string | null;
    tag: string | null;
    low_stock: string | null;
    sort: string | null;
}

interface InventoryFilterFieldsProps {
    values: InventorySheetValues;
    onPick: (key: keyof InventorySheetValues, value: string | null) => void;
    categories: readonly TaxonomyOption[];
    tags: readonly TaxonomyOption[];
    sortable: boolean;
    stacked?: boolean;
}

function options(all: string, items: readonly TaxonomyOption[]) {
    return [{ value: '', label: all }, ...items.map((item) => ({ value: String(item.id), label: item.name }))];
}

export function InventoryFilterFields({ values, onPick, categories, tags, sortable, stacked = false }: InventoryFilterFieldsProps) {
    const pick = (key: keyof InventorySheetValues) => (event: { target: { value: string } }) =>
        onPick(key, event.target.value === '' ? null : event.target.value);

    return (
        <>
            <SelectField
                label="Categoría"
                hideLabel={!stacked}
                inline={!stacked}
                name="category"
                options={options(stacked ? 'Todas' : 'Categoría', categories)}
                value={values.category ?? ''}
                onChange={pick('category')}
            />
            <SelectField
                label="Etiqueta"
                hideLabel={!stacked}
                inline={!stacked}
                name="tag"
                options={options(stacked ? 'Todas' : 'Etiqueta', tags)}
                value={values.tag ?? ''}
                onChange={pick('tag')}
            />
            {sortable ? <InventorySortSelect sort={values.sort} onChange={(sort) => onPick('sort', sort)} stacked={stacked} /> : null}

            <ToggleField
                stacked={stacked}
                className={stacked ? undefined : 'lg:ml-auto'}
                checked={values.low_stock === '1'}
                onChange={(on) => onPick('low_stock', on ? '1' : null)}
            >
                Solo los que se están acabando
            </ToggleField>
        </>
    );
}

export function inventoryChipLabels(values: InventorySheetValues, categories: readonly TaxonomyOption[], tags: readonly TaxonomyOption[]) {
    return present([
        optionChip('category', 'Categoría', values.category, options('', categories)),
        optionChip('tag', 'Etiqueta', values.tag, options('', tags)),
        flagChip('low_stock', 'Se están acabando', values.low_stock === '1'),
    ]);
}
