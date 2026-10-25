import { SelectField } from '@/components/ui/select-field';

const SORT_OPTIONS = [
    { value: '', label: 'Recientes' },
    { value: 'name', label: 'Nombre (A–Z)' },
    { value: '-name', label: 'Nombre (Z–A)' },
    { value: 'price', label: 'Precio (menor primero)' },
    { value: '-price', label: 'Precio (mayor primero)' },
    { value: 'stock', label: 'Stock (menos primero)' },
    { value: '-stock', label: 'Stock (más primero)' },
    { value: '-updated', label: 'Última modificación' },
    { value: 'sku', label: 'SKU' },
] as const;

interface InventorySortSelectProps {
    sort: string | null;
    onChange: (sort: string | null) => void;
}

const DEFAULT_SORT = '-created';

export function InventorySortSelect({ sort: applied, onChange }: InventorySortSelectProps) {
    const sort = applied === DEFAULT_SORT ? null : applied;
    const known = SORT_OPTIONS.some((option) => option.value === (sort ?? ''));
    const options = known ? SORT_OPTIONS : [...SORT_OPTIONS, { value: sort ?? '', label: 'Por columna' }];

    return (
        <SelectField
            label="Ordenar"
            hideLabel
            inline
            name="sort"
            options={options.map((option) => ({ value: option.value, label: `Ordenar: ${option.label}` }))}
            value={sort ?? ''}
            onChange={(event) => onChange(event.target.value === '' ? null : event.target.value)}
        />
    );
}
