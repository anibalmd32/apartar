import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilSearch } from '@coreui/icons';

export const RISK_TOGGLES = [
    ['vencidas', 'Con factura vencida'],
    ['al_limite', 'En el límite'],
    ['inactivas', 'Inactivas 30 d'],
    ['cerradas', 'Incluir cerradas'],
] as const;

type Change = Record<string, string | boolean | number | null>;

interface OrganizationFiltersProps {
    filters: Record<string, string | boolean | null>;
    plans: Readonly<Record<string, string>>;
    onChange: (changes: Change) => void;
}

const pill = 'h-[34px] cursor-pointer rounded-full border px-3 text-sm font-medium whitespace-nowrap transition-colors';

export function OrganizationFilters({ filters, plans, onChange }: OrganizationFiltersProps) {
    const suspended = filters.estado === 'suspended';
    const noneActive = RISK_TOGGLES.every(([key]) => filters[key] !== true) && !suspended;

    return (
        <form role="search" className="flex flex-wrap items-center gap-2" onSubmit={(event) => event.preventDefault()}>
            <label className="input h-10 w-full gap-2 sm:w-[300px]">
                <Icon icon={cilSearch} className="text-ink-3" />
                <span className="sr-only">Buscar</span>
                <input
                    className="grow"
                    placeholder="Nombre o enlace"
                    defaultValue={typeof filters.q === 'string' ? filters.q : ''}
                    onKeyDown={(event) => event.key === 'Enter' && onChange({ q: event.currentTarget.value || null })}
                />
            </label>

            <div className="flex flex-wrap gap-2">
                <button
                    type="button"
                    aria-pressed={noneActive}
                    className={cn(pill, noneActive ? 'bg-neutral text-neutral-content border-neutral' : 'border-line-strong bg-base-100')}
                    onClick={() => onChange({ ...Object.fromEntries(RISK_TOGGLES.map(([key]) => [key, false])), estado: null })}
                >
                    Todas
                </button>
                {RISK_TOGGLES.map(([key, label]) => {
                    const active = filters[key] === true;

                    return (
                        <button
                            key={key}
                            type="button"
                            aria-pressed={active}
                            className={cn(pill, active ? 'bg-neutral text-neutral-content border-neutral' : 'border-line-strong bg-base-100')}
                            onClick={() => onChange({ [key]: !active })}
                        >
                            {label}
                        </button>
                    );
                })}
                <button
                    type="button"
                    aria-pressed={suspended}
                    className={cn(pill, suspended ? 'bg-neutral text-neutral-content border-neutral' : 'border-line-strong bg-base-100')}
                    onClick={() => onChange({ estado: suspended ? null : 'suspended' })}
                >
                    Suspendidas
                </button>
            </div>

            <label className="select h-[34px] w-auto rounded-full text-sm">
                <span className="sr-only">Plan</span>
                <select
                    value={typeof filters.plan === 'string' ? filters.plan : ''}
                    onChange={(event) => onChange({ plan: event.target.value || null })}
                >
                    <option value="">Plan: todos</option>
                    {Object.entries(plans).map(([code, name]) => (
                        <option key={code} value={code}>
                            {name}
                        </option>
                    ))}
                </select>
            </label>
        </form>
    );
}
