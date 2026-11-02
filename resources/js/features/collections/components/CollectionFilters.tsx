import { Chip } from '@/components/ui/chip';
import { DateRangeField } from '@/components/ui/date-range-field';
import { FilterBar } from '@/components/ui/filter-bar';
import { ScrollRow } from '@/components/ui/scroll-row';
import { SearchField } from '@/components/ui/search-field';
import { SelectField } from '@/components/ui/select-field';
import { useFilterDraft } from '@/hooks/use-filter-draft';
import { dateChip, optionChip, present } from '@/lib/filter-chips';
import { type CollectionFiltersState, type CollectionOptions } from '@/types/collections';

type SheetKey = 'status' | 'category' | 'catalog' | 'from' | 'to';
type SheetValues = Pick<CollectionFiltersState, SheetKey>;

interface CollectionFiltersProps {
    filters: CollectionFiltersState;
    options: CollectionOptions;
    onChange: (key: keyof CollectionFiltersState, value: string | null) => void;
    onChangeMany: (patch: Partial<Record<keyof CollectionFiltersState, string | null>>) => void;
    counts?: Readonly<Record<string, number>>;
}

export const COLLECTION_FILTER_LABELS: Readonly<Record<keyof CollectionFiltersState, string>> = {
    preset: 'filtro rápido',
    from: 'vence desde',
    to: 'vence hasta',
    status: 'estado',
    q: 'cliente',
    category: 'categoría',
    catalog: 'catálogo',
};

const CLEARED: Record<SheetKey, null> = { status: null, category: null, catalog: null, from: null, to: null };

function Fields({
    values,
    options,
    onPick,
    stacked = false,
}: {
    values: SheetValues;
    options: CollectionOptions;
    onPick: (key: SheetKey, value: string | null) => void;
    stacked?: boolean;
}) {
    const pick = (key: SheetKey) => (event: { target: { value: string } }) => onPick(key, event.target.value === '' ? null : event.target.value);

    return (
        <>
            <SelectField
                label="Estado del apartado"
                hideLabel={!stacked}
                inline={!stacked}
                name="status"
                options={[{ value: '', label: stacked ? 'Los que cobran' : 'Estado: los que cobran' }, ...options.statuses]}
                value={values.status ?? ''}
                onChange={pick('status')}
            />
            <SelectField
                label="Categoría"
                hideLabel={!stacked}
                inline={!stacked}
                name="category"
                options={[{ value: '', label: stacked ? 'Todas' : 'Categoría' }, ...options.categories]}
                value={values.category ?? ''}
                onChange={pick('category')}
            />
            <SelectField
                label="Catálogo de origen"
                hideLabel={!stacked}
                inline={!stacked}
                name="catalog"
                options={[{ value: '', label: stacked ? 'Todos' : 'Catálogo de origen' }, ...options.catalogs]}
                value={values.catalog ?? ''}
                onChange={pick('catalog')}
            />
            <DateRangeField
                label="Fecha de vencimiento"
                fromLabel="Vence desde"
                toLabel="Vence hasta"
                showLabel={stacked}
                from={values.from ?? ''}
                to={values.to ?? ''}
                onFromChange={pick('from')}
                onToChange={pick('to')}
            />
        </>
    );
}

export function CollectionFilters({ filters, options, onChange, onChangeMany, counts = {} }: CollectionFiltersProps) {
    const applied: SheetValues = { status: filters.status, category: filters.category, catalog: filters.catalog, from: filters.from, to: filters.to };
    const sheet = useFilterDraft(applied);
    const intersects = filters.preset !== null && (filters.from !== null || filters.to !== null);
    const chips = present([
        optionChip('status', 'Estado', filters.status, options.statuses),
        optionChip('category', 'Categoría', filters.category, options.categories),
        optionChip('catalog', 'Catálogo', filters.catalog, options.catalogs),
        dateChip('from', 'Vence desde', filters.from),
        dateChip('to', 'Vence hasta', filters.to),
    ]);

    return (
        <div className="flex flex-col gap-3 lg:gap-4">
            <ScrollRow role="group" aria-label="Filtros rápidos" activeKey={filters.preset} className="flex gap-2 lg:flex-wrap lg:overflow-visible">
                {options.presets.map((preset) => (
                    <Chip
                        key={preset.value}
                        active={filters.preset === preset.value}
                        count={counts[preset.value]}
                        onClick={() => onChange('preset', filters.preset === preset.value ? null : preset.value)}
                    >
                        {preset.label}
                    </Chip>
                ))}
            </ScrollRow>

            <FilterBar
                search={
                    <SearchField
                        label="Cliente"
                        name="q"
                        className="lg:w-64"
                        placeholder="Buscar cliente"
                        title="Cédula, teléfono, correo o nombre."
                        value={filters.q ?? ''}
                        onChange={(event) => onChange('q', event.target.value === '' ? null : event.target.value)}
                    />
                }
                inline={<Fields values={applied} options={options} onPick={onChange} />}
                sheet={<Fields values={sheet.draft} options={options} onPick={sheet.set} stacked />}
                chips={chips.map((chip) => ({ ...chip, onRemove: () => onChange(chip.key, null) }))}
                onOpen={sheet.reset}
                onApply={() => onChangeMany(sheet.changes())}
                onClear={() => onChangeMany(CLEARED)}
            />

            {intersects ? <p className="text-ink-3 text-xs">El filtro rápido y el rango se combinan: puede que no quede ninguna cuota.</p> : null}
        </div>
    );
}
