import { Chip } from '@/components/ui/chip';
import { Icon } from '@/components/ui/icon';
import { SelectField } from '@/components/ui/select-field';
import { type CustomerDirectoryFilters, type CustomerIndexProps } from '@/types/customers';
import { cilCalendar, cilFilter, cilSearch } from '@coreui/icons';
import { type ChangeEvent, useState } from 'react';

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

export function DirectoryFilters({ filters, search, onSearch, options, onChange, onChangeMany, counts }: DirectoryFiltersProps) {
    const pick = (key: keyof CustomerDirectoryFilters) => (event: ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
        onChange(key, event.target.value === '' ? null : event.target.value);

    const [more, setMore] = useState(filters.from !== null || filters.to !== null || filters.catalog !== null);

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <label className="input w-full sm:w-80">
                    <Icon icon={cilSearch} className="text-ink-3" />
                    <span className="sr-only">Buscar</span>
                    <input
                        name="q"
                        value={search}
                        placeholder="Nombre, cédula/RIF, teléfono o correo"
                        title="Cédula, teléfono, correo o nombre: se busca por lo que escribas."
                        onChange={(event) => onSearch(event.target.value)}
                    />
                </label>

                <div className="flex flex-wrap gap-2" role="group" aria-label="Filtros rápidos">
                    <Chip
                        active={TOGGLES.every((toggle) => filters[toggle.key] !== '1')}
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
                </div>

                <button type="button" className="btn lg:ml-auto" aria-expanded={more} onClick={() => setMore(!more)}>
                    <Icon icon={cilFilter} />
                    Más filtros
                </button>
            </div>

            {more ? (
                <div className="flex flex-wrap items-center gap-2">
                    <label className="input w-auto">
                        <Icon icon={cilCalendar} className="text-ink-3" />
                        <span className="text-ink-3 text-[13px]">Próximo vencimiento desde</span>
                        <input name="from" type="date" value={filters.from ?? ''} onChange={pick('from')} />
                        <span className="text-ink-3 text-[13px]">hasta</span>
                        <input name="to" type="date" aria-label="Próximo vencimiento hasta" value={filters.to ?? ''} onChange={pick('to')} />
                    </label>
                    <SelectField
                        label="Catálogo de origen"
                        hideLabel
                        inline
                        name="catalog"
                        options={[{ value: '', label: 'Catálogo de origen' }, ...options.catalogs]}
                        value={filters.catalog ?? ''}
                        onChange={pick('catalog')}
                    />
                </div>
            ) : null}
        </div>
    );
}
