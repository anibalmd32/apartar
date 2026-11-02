import { DateRangeField } from '@/components/ui/date-range-field';
import { FilterBar } from '@/components/ui/filter-bar';
import { SelectField } from '@/components/ui/select-field';
import { useFilterDraft } from '@/hooks/use-filter-draft';
import { dateChip, optionChip, present } from '@/lib/filter-chips';
import { type ReactNode } from 'react';

interface Option {
    readonly value: string;
    readonly label: string;
}

export interface ListSelect {
    key: string;
    label: string;
    all: string;
    options: readonly Option[];
}

type Values = Record<string, string | null>;

interface PlatformListFiltersProps {
    selects: readonly ListSelect[];
    values: Values;
    onChange: (changes: Values) => void;
    search?: ReactNode;
    className?: string;
}

function Fields({
    selects,
    values,
    onPick,
    stacked = false,
}: {
    selects: readonly ListSelect[];
    values: Values;
    onPick: (key: string, value: string | null) => void;
    stacked?: boolean;
}) {
    return (
        <>
            {selects.map((select) => (
                <SelectField
                    key={select.key}
                    label={select.label}
                    value={values[select.key] ?? ''}
                    onChange={(event) => onPick(select.key, event.target.value === '' ? null : event.target.value)}
                    options={[{ value: '', label: select.all }, ...select.options]}
                />
            ))}
            <DateRangeField
                label="Fechas"
                fromLabel="Desde"
                showLabel={stacked}
                from={values.desde ?? ''}
                to={values.hasta ?? ''}
                fromName="desde"
                toName="hasta"
                onFromChange={(event) => onPick('desde', event.target.value === '' ? null : event.target.value)}
                onToChange={(event) => onPick('hasta', event.target.value === '' ? null : event.target.value)}
                className={stacked ? undefined : 'self-end'}
            />
        </>
    );
}

export function PlatformListFilters({ selects, values, onChange, search, className }: PlatformListFiltersProps) {
    const keys = [...selects.map((select) => select.key), 'desde', 'hasta'];
    const applied = Object.fromEntries(keys.map((key) => [key, values[key] ?? null])) as Values;
    const sheet = useFilterDraft(applied);
    const chips = present([
        ...selects.map((select) => optionChip(select.key, select.label, applied[select.key], select.options)),
        dateChip('desde', 'Desde', applied.desde),
        dateChip('hasta', 'Hasta', applied.hasta),
    ]);

    return (
        <section aria-label="Filtros" className="flex flex-col gap-3">
            <FilterBar
                className={className}
                search={search}
                inline={<Fields selects={selects} values={applied} onPick={(key, value) => onChange({ [key]: value })} />}
                sheet={<Fields selects={selects} values={sheet.draft} onPick={sheet.set} stacked />}
                chips={chips.map((chip) => ({ ...chip, onRemove: () => onChange({ [chip.key]: null }) }))}
                onOpen={sheet.reset}
                onApply={() => onChange(sheet.changes() as Values)}
                onClear={() => onChange(Object.fromEntries(keys.map((key) => [key, null])))}
            />
        </section>
    );
}
