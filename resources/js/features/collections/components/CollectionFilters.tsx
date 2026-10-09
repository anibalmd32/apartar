import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { type CollectionFiltersState, type CollectionOptions } from '@/types/collections';
import { type ChangeEvent } from 'react';

/**
 * Los filtros de la cartera (`REQ-BOF-042` RB-2, RB-3): los presets como
 * botones —son lo que se usa a diario—, y debajo lo que se combina con
 * ellos: rango de vencimiento, estado del apartado, categoría, catálogo de
 * origen y la búsqueda del cliente.
 *
 * Pintan lo que el servidor **aplicó**; lo que ignoró —un rango invertido,
 * una fecha que no existe— lo avisa la tabla (`REQ-BOF-045` SP-4).
 */

interface CollectionFiltersProps {
    filters: CollectionFiltersState;
    options: CollectionOptions;
    onChange: (key: keyof CollectionFiltersState, value: string | null) => void;
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

export function CollectionFilters({ filters, options, onChange }: CollectionFiltersProps) {
    const pick = (key: keyof CollectionFiltersState) => (event: ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
        onChange(key, event.target.value === '' ? null : event.target.value);
    // SP-3 · un preset y un rango se intersecan: puede no quedar nada.
    const intersects = filters.preset !== null && (filters.from !== null || filters.to !== null);

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap gap-2" role="group" aria-label="Filtros rápidos">
                {options.presets.map((preset) => (
                    <button
                        key={preset.value}
                        type="button"
                        className={`btn btn-sm ${filters.preset === preset.value ? 'btn-primary' : 'btn-outline'}`}
                        aria-pressed={filters.preset === preset.value}
                        onClick={() => onChange('preset', filters.preset === preset.value ? null : preset.value)}
                    >
                        {preset.label}
                    </button>
                ))}
            </div>

            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <TextField label="Cliente" name="q" value={filters.q ?? ''} onChange={pick('q')} hint="Cédula, teléfono, correo o nombre." />
                <TextField label="Vence desde" name="from" type="date" value={filters.from ?? ''} onChange={pick('from')} />
                <TextField label="Vence hasta" name="to" type="date" value={filters.to ?? ''} onChange={pick('to')} />
                <SelectField
                    label="Estado del apartado"
                    name="status"
                    options={[{ value: '', label: 'Los que cobran' }, ...options.statuses]}
                    value={filters.status ?? ''}
                    onChange={pick('status')}
                />
                <SelectField
                    label="Categoría"
                    name="category"
                    options={[{ value: '', label: 'Todas' }, ...options.categories]}
                    value={filters.category ?? ''}
                    onChange={pick('category')}
                />
                <SelectField
                    label="Catálogo de origen"
                    name="catalog"
                    options={[{ value: '', label: 'Todos' }, ...options.catalogs]}
                    value={filters.catalog ?? ''}
                    onChange={pick('catalog')}
                />
            </div>

            {intersects ? <p className="text-xs opacity-70">El filtro rápido y el rango se combinan: puede que no quede ninguna cuota.</p> : null}
        </div>
    );
}
