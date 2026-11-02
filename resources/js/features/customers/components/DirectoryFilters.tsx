import { Chip } from '@/components/ui/chip';
import { DateRangeField } from '@/components/ui/date-range-field';
import { FilterBar } from '@/components/ui/filter-bar';
import { Icon } from '@/components/ui/icon';
import { ScrollRow } from '@/components/ui/scroll-row';
import { SearchField } from '@/components/ui/search-field';
import { SelectField } from '@/components/ui/select-field';
import { useFilterDraft } from '@/hooks/use-filter-draft';
import { dateChip, optionChip, present } from '@/lib/filter-chips';
import { type CustomerDirectoryFilters, type CustomerIndexProps } from '@/types/customers';
import { cilFilter } from '@coreui/icons';
import { useState } from 'react';

type SheetValues = Pick<CustomerDirectoryFilters, 'from' | 'to' | 'catalog'>;

interface DirectoryFiltersProps {
    filters: CustomerDirectoryFilters;
    search: string;
    onSearch: (value: string) => void;
    options: CustomerIndexProps['options'];
    onChange: (key: keyof CustomerDirectoryFilters, value: string | null) => void;
    counts?: CustomerIndexProps['filterCounts'];
    onChangeMany: (patch: Partial<Record<keyof CustomerDirectoryFilters, string | null>>) => void;
}

export const DIRECTORY_FILTER_LABELS: Readonly<Record<keyof CustomerDirectoryFilters, string>> = {
    q: 'búsqueda',
    overdue: 'con mora',
    due_soon: 'con vencimiento próximo',
    without_active: 'sin apartados activos',
    from: 'vence desde',
    to: 'vence hasta',
    catalog: 'catálogo de origen',
};

const TOGGLES: readonly { key: 'overdue' | 'due_soon' | 'without_active'; label: string }[] = [
    { key: 'overdue', label: 'En mora' },
    { key: 'due_soon', label: 'Vencen pronto' },
    { key: 'without_active', label: 'Sin apartados activos' },
];

function Fields({
    values,
    options,
    onPick,
    stacked = false,
}: {
    values: SheetValues;
    options: CustomerIndexProps['options'];
    onPick: (key: keyof SheetValues, value: string | null) => void;
    stacked?: boolean;
}) {
    const pick = (key: keyof SheetValues) => (event: { target: { value: string } }) =>
        onPick(key, event.target.value === '' ? null : event.target.value);

    return (
        <>
            <DateRangeField
                label="Próximo vencimiento"
                fromLabel="Vence desde"
                toLabel="Vence hasta"
                showLabel={stacked}
                from={values.from ?? ''}
                to={values.to ?? ''}
                onFromChange={pick('from')}
                onToChange={pick('to')}
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
        </>
    );
}

export function DirectoryFilters({ filters, search, onSearch, options, onChange, onChangeMany, counts }: DirectoryFiltersProps) {
    const applied: SheetValues = { from: filters.from, to: filters.to, catalog: filters.catalog };
    const sheet = useFilterDraft(applied);
    const [more, setMore] = useState(filters.from !== null || filters.to !== null || filters.catalog !== null);
    const allActive = TOGGLES.every((toggle) => filters[toggle.key] !== '1');
    const chips = present([
        dateChip('from', 'Vence desde', filters.from),
        dateChip('to', 'Vence hasta', filters.to),
        optionChip('catalog', 'Catálogo', filters.catalog, options.catalogs),
    ]);

    return (
        <div className="flex flex-col gap-3">
            <FilterBar
                search={
                    <SearchField
                        label="Buscar"
                        name="q"
                        className="lg:w-80"
                        value={search}
                        placeholder="Buscar cliente"
                        title="Cédula, teléfono, correo o nombre: se busca por lo que escribas."
                        onChange={(event) => onSearch(event.target.value)}
                    />
                }
                quick={
                    <ScrollRow
                        role="group"
                        aria-label="Filtros rápidos"
                        activeKey={allActive ? 'all' : 'some'}
                        className="flex gap-2 lg:flex-wrap lg:overflow-visible"
                    >
                        <Chip
                            active={allActive}
                            count={counts?.all}
                            onClick={() => onChangeMany(Object.fromEntries(TOGGLES.map((toggle) => [toggle.key, null])))}
                        >
                            Todos
                        </Chip>
                        {TOGGLES.map((toggle) => (
                            <Chip
                                key={toggle.key}
                                active={filters[toggle.key] === '1'}
                                count={counts?.[toggle.key]}
                                onClick={() => onChange(toggle.key, filters[toggle.key] === '1' ? null : '1')}
                            >
                                {toggle.label}
                            </Chip>
                        ))}
                    </ScrollRow>
                }
                inline={
                    <button type="button" className="btn lg:ml-auto" aria-expanded={more} onClick={() => setMore(!more)}>
                        <Icon icon={cilFilter} />
                        Más filtros
                    </button>
                }
                sheet={<Fields values={sheet.draft} options={options} onPick={sheet.set} stacked />}
                chips={chips.map((chip) => ({ ...chip, onRemove: () => onChange(chip.key, null) }))}
                onOpen={sheet.reset}
                onApply={() => onChangeMany(sheet.changes())}
                onClear={() => onChangeMany({ from: null, to: null, catalog: null })}
            />

            {more ? (
                <div className="flex flex-wrap items-center gap-2 max-lg:hidden">
                    <Fields values={applied} options={options} onPick={onChange} />
                </div>
            ) : null}
        </div>
    );
}
