import { Chip } from '@/components/ui/chip';
import { Icon } from '@/components/ui/icon';
import { SelectField } from '@/components/ui/select-field';
import { type CollectionFiltersState, type CollectionOptions } from '@/types/collections';
import { cilCalendar, cilSearch } from '@coreui/icons';
import { type ChangeEvent } from 'react';

interface CollectionFiltersProps {
    filters: CollectionFiltersState;
    options: CollectionOptions;
    onChange: (key: keyof CollectionFiltersState, value: string | null) => void;
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

export function CollectionFilters({ filters, options, onChange, counts = {} }: CollectionFiltersProps) {
    const pick = (key: keyof CollectionFiltersState) => (event: ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
        onChange(key, event.target.value === '' ? null : event.target.value);
    const intersects = filters.preset !== null && (filters.from !== null || filters.to !== null);

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap gap-2" role="group" aria-label="Filtros rápidos">
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
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <label className="input w-full sm:w-64">
                    <Icon icon={cilSearch} className="text-ink-3" />
                    <span className="sr-only">Cliente</span>
                    <input
                        name="q"
                        placeholder="Buscar cliente"
                        title="Cédula, teléfono, correo o nombre."
                        value={filters.q ?? ''}
                        onChange={pick('q')}
                    />
                </label>
                <SelectField
                    label="Estado del apartado"
                    hideLabel
                    inline
                    name="status"
                    options={[{ value: '', label: 'Estado: los que cobran' }, ...options.statuses]}
                    value={filters.status ?? ''}
                    onChange={pick('status')}
                />
                <SelectField
                    label="Categoría"
                    hideLabel
                    inline
                    name="category"
                    options={[{ value: '', label: 'Categoría' }, ...options.categories]}
                    value={filters.category ?? ''}
                    onChange={pick('category')}
                />
                <SelectField
                    label="Catálogo de origen"
                    hideLabel
                    inline
                    name="catalog"
                    options={[{ value: '', label: 'Catálogo de origen' }, ...options.catalogs]}
                    value={filters.catalog ?? ''}
                    onChange={pick('catalog')}
                />
                <label className={filters.from !== null || filters.to !== null ? 'input border-primary bg-primary-soft w-auto' : 'input w-auto'}>
                    <Icon icon={cilCalendar} className="text-ink-3" />
                    <span className="text-ink-3 text-[13px]">Vence desde</span>
                    <input name="from" type="date" value={filters.from ?? ''} onChange={pick('from')} />
                    <span className="text-ink-3 text-[13px]">hasta</span>
                    <input name="to" type="date" aria-label="Vence hasta" value={filters.to ?? ''} onChange={pick('to')} />
                </label>
            </div>

            {intersects ? <p className="text-ink-3 text-xs">El filtro rápido y el rango se combinan: puede que no quede ninguna cuota.</p> : null}
        </div>
    );
}
