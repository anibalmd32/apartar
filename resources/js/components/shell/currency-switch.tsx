import { useCurrencyDisplay } from '@/features/currency/hooks/useCurrencyDisplay';
import { cn } from '@/lib/utils';
import { type CurrencyPageProps } from '@/types/exchange';
import { usePage } from '@inertiajs/react';

interface CurrencySwitchProps {
    className?: string;
    touch?: boolean;
    compact?: boolean;
}

const UNAVAILABLE = 'No hay tasa de cambio disponible, así que los precios solo se muestran en dólares';

export function CurrencySwitch({ className, touch = false, compact = false }: CurrencySwitchProps) {
    const { currency } = usePage<{ currency?: CurrencyPageProps }>().props;
    const { isVesPrimary, setDisplay } = useCurrencyDisplay();

    const available = currency?.rate?.rate !== null && currency?.rate?.rate !== undefined;

    if (compact) {
        const label = isVesPrimary ? 'Ver precios en dólares' : 'Ver precios en bolívares';

        return (
            <button
                type="button"
                onClick={() => setDisplay(isVesPrimary ? 'usd_primary' : 'ves_primary')}
                disabled={!available}
                aria-label={available ? label : 'Precios en dólares: no hay tasa para pasar a bolívares'}
                title={available ? label : UNAVAILABLE}
                className={cn(
                    'bg-surface-2 text-base-content touch:size-11 inline-flex size-10 shrink-0 items-center justify-center rounded-[10px] text-[13px] font-bold disabled:cursor-not-allowed disabled:opacity-60',
                    className,
                )}
            >
                {isVesPrimary ? 'Bs.' : '$'}
            </button>
        );
    }

    const option = (active: boolean, label: string, onClick: () => void) => (
        <button
            type="button"
            onClick={onClick}
            disabled={!available}
            aria-pressed={active}
            className={cn(
                'rounded-lg px-2.5 text-[13px] transition-colors disabled:cursor-not-allowed',
                touch ? 'hit-area h-[38px] min-w-11' : 'hit-area touch:h-[38px] touch:min-w-11 h-[30px]',
                active ? 'bg-base-100 dark:bg-line-strong shadow-card font-bold' : 'text-ink-3 hover:text-base-content font-medium',
            )}
        >
            {label}
        </button>
    );

    return (
        <div
            role="group"
            aria-label="Moneda que se muestra primero"
            title={available ? undefined : UNAVAILABLE}
            className={cn('bg-surface-2 inline-flex items-center gap-0.5 rounded-[10px] p-[3px]', !available && 'opacity-60', className)}
        >
            {option(!isVesPrimary, 'USD', () => setDisplay('usd_primary'))}
            {option(isVesPrimary, 'Bs.', () => setDisplay('ves_primary'))}
        </div>
    );
}
