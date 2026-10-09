import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { type CustomerDirectoryFilters, type CustomerIndexProps } from '@/types/customers';
import { type ChangeEvent } from 'react';

/**
 * Los filtros del directorio (`REQ-BOF-040` RB-1, RB-4): una sola caja que
 * decide el criterio —cédula, teléfono, correo o nombre—, y los filtros de
 * cobranza: con mora, con vencimiento próximo, sin apartados activos, el rango
 * del próximo vencimiento y el catálogo de origen.
 */

interface DirectoryFiltersProps {
    filters: CustomerDirectoryFilters;
    search: string;
    onSearch: (value: string) => void;
    options: CustomerIndexProps['options'];
    onChange: (key: keyof CustomerDirectoryFilters, value: string | null) => void;
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
    { key: 'overdue', label: 'Con mora' },
    { key: 'due_soon', label: 'Con vencimiento próximo' },
    { key: 'without_active', label: 'Sin apartados activos' },
];

export function DirectoryFilters({ filters, search, onSearch, options, onChange }: DirectoryFiltersProps) {
    const pick = (key: keyof CustomerDirectoryFilters) => (event: ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
        onChange(key, event.target.value === '' ? null : event.target.value);

    return (
        <div className="flex flex-col gap-3">
            <TextField
                label="Buscar"
                name="q"
                value={search}
                onChange={(event) => onSearch(event.target.value)}
                hint="Cédula, teléfono, correo o nombre: se busca por lo que escribas."
            />

            <div className="flex flex-wrap gap-4">
                {TOGGLES.map((toggle) => (
                    <label key={toggle.key} className="label cursor-pointer gap-2">
                        <input
                            type="checkbox"
                            className="checkbox checkbox-sm"
                            checked={filters[toggle.key] === '1'}
                            onChange={(event) => onChange(toggle.key, event.target.checked ? '1' : null)}
                        />
                        <span className="label-text">{toggle.label}</span>
                    </label>
                ))}
            </div>

            <div className="grid gap-2 sm:grid-cols-3">
                <TextField label="Próximo vencimiento desde" name="from" type="date" value={filters.from ?? ''} onChange={pick('from')} />
                <TextField label="Próximo vencimiento hasta" name="to" type="date" value={filters.to ?? ''} onChange={pick('to')} />
                <SelectField
                    label="Catálogo de origen"
                    name="catalog"
                    options={[{ value: '', label: 'Todos' }, ...options.catalogs]}
                    value={filters.catalog ?? ''}
                    onChange={pick('catalog')}
                />
            </div>
        </div>
    );
}
