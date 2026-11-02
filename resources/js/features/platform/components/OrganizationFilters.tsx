import { FilterBar } from '@/components/ui/filter-bar';
import { ScrollRow } from '@/components/ui/scroll-row';
import { SearchField } from '@/components/ui/search-field';
import { SelectField } from '@/components/ui/select-field';
import { ToggleField } from '@/components/ui/toggle-field';
import { useFilterDraft } from '@/hooks/use-filter-draft';
import { flagChip, optionChip, present } from '@/lib/filter-chips';
import { cn } from '@/lib/utils';

export const RISK_TOGGLES = [
    ['vencidas', 'Con factura vencida'],
    ['al_limite', 'En el límite'],
    ['inactivas', 'Inactivas 30 d'],
] as const;

type Change = Record<string, string | boolean | number | null>;

interface SheetValues {
    plan: string | null;
    cerradas: boolean;
}

interface OrganizationFiltersProps {
    filters: Record<string, string | boolean | null>;
    plans: Readonly<Record<string, string>>;
    onChange: (changes: Change) => void;
}

const pill = 'h-[34px] touch:h-11 shrink-0 cursor-pointer rounded-full border px-3 text-sm font-medium whitespace-nowrap transition-colors';

function pillClass(active: boolean) {
    return cn(pill, active ? 'bg-neutral text-neutral-content border-neutral' : 'border-line-strong bg-base-100');
}

function Fields({
    values,
    plans,
    onPick,
    stacked = false,
}: {
    values: SheetValues;
    plans: Readonly<Record<string, string>>;
    onPick: <K extends keyof SheetValues>(key: K, value: SheetValues[K]) => void;
    stacked?: boolean;
}) {
    const options = [{ value: '', label: stacked ? 'Todos' : 'Plan: todos' }, ...Object.entries(plans).map(([value, label]) => ({ value, label }))];

    return (
        <>
            <SelectField
                label="Plan"
                hideLabel={!stacked}
                inline={!stacked}
                className={stacked ? undefined : 'lg:touch:h-11 lg:h-[34px] lg:rounded-full lg:text-sm'}
                options={options}
                value={values.plan ?? ''}
                onChange={(event) => onPick('plan', event.target.value || null)}
            />
            <ToggleField
                stacked={stacked}
                className={stacked ? undefined : 'lg:ml-auto'}
                checked={values.cerradas}
                onChange={(cerradas) => onPick('cerradas', cerradas)}
            >
                Incluir cerradas
            </ToggleField>
        </>
    );
}

export function OrganizationFilters({ filters, plans, onChange }: OrganizationFiltersProps) {
    const suspended = filters.estado === 'suspended';
    const noneActive = RISK_TOGGLES.every(([key]) => filters[key] !== true) && !suspended;
    const applied: SheetValues = { plan: typeof filters.plan === 'string' ? filters.plan : null, cerradas: filters.cerradas === true };
    const sheet = useFilterDraft(applied);
    const planOptions = Object.entries(plans).map(([value, label]) => ({ value, label }));
    const chips = present([optionChip('plan', 'Plan', applied.plan, planOptions), flagChip('cerradas', 'Incluye cerradas', applied.cerradas)]);

    return (
        <form role="search" className="flex flex-col gap-3" onSubmit={(event) => event.preventDefault()}>
            <FilterBar
                search={
                    <SearchField
                        label="Buscar"
                        className="h-10 lg:w-[300px]"
                        placeholder="Nombre o enlace"
                        defaultValue={typeof filters.q === 'string' ? filters.q : ''}
                        onKeyDown={(event) => event.key === 'Enter' && onChange({ q: event.currentTarget.value || null })}
                    />
                }
                quick={
                    <ScrollRow
                        role="group"
                        aria-label="Filtros rápidos"
                        activeKey={noneActive ? 'all' : 'some'}
                        className="flex gap-2 lg:flex-wrap lg:overflow-visible"
                    >
                        <button
                            type="button"
                            aria-pressed={noneActive}
                            className={pillClass(noneActive)}
                            onClick={() => onChange({ ...Object.fromEntries(RISK_TOGGLES.map(([key]) => [key, false])), estado: null })}
                        >
                            Todas
                        </button>
                        {RISK_TOGGLES.map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                aria-pressed={filters[key] === true}
                                className={pillClass(filters[key] === true)}
                                onClick={() => onChange({ [key]: filters[key] !== true })}
                            >
                                {label}
                            </button>
                        ))}
                        <button
                            type="button"
                            aria-pressed={suspended}
                            className={pillClass(suspended)}
                            onClick={() => onChange({ estado: suspended ? null : 'suspended' })}
                        >
                            Suspendidas
                        </button>
                    </ScrollRow>
                }
                inline={<Fields values={applied} plans={plans} onPick={(key, value) => onChange({ [key]: value })} />}
                sheet={<Fields values={sheet.draft} plans={plans} onPick={sheet.set} stacked />}
                chips={chips.map((chip) => ({ ...chip, onRemove: () => onChange({ [chip.key]: chip.key === 'cerradas' ? false : null }) }))}
                onOpen={sheet.reset}
                onApply={() => onChange(sheet.changes())}
                onClear={() => onChange({ plan: null, cerradas: false })}
            />
        </form>
    );
}
