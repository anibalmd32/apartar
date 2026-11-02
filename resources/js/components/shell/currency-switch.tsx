import { useCurrencyDisplay } from '@/features/currency/hooks/useCurrencyDisplay';
import { cn } from '@/lib/utils';
import { type CurrencyPageProps } from '@/types/exchange';
import { usePage } from '@inertiajs/react';

export function CurrencySwitch({ className, touch = false }: { className?: string; touch?: boolean }) {
    const { currency } = usePage<{ currency?: CurrencyPageProps }>().props;
    const { isVesPrimary, setDisplay } = useCurrencyDisplay();

    const available = currency?.rate?.rate !== null && currency?.rate?.rate !== undefined;

    const option = (active: boolean, label: string, onClick: () => void) => (
        <button
            type="button"
            onClick={onClick}
            disabled={!available}
            aria-pressed={active}
            className={cn(
                'rounded-lg px-2.5 text-[13px] transition-colors disabled:cursor-not-allowed',
                touch ? 'hit-area h-[38px] min-w-11' : 'pointer-coarse:h-10 pointer-coarse:min-w-11 h-[30px]',
                active ? 'bg-base-100 dark:bg-base-300 shadow-card font-bold' : 'text-ink-3 hover:text-base-content font-medium',
            )}
        >
            {label}
        </button>
    );

    return (
        <div
            role="group"
            aria-label="Moneda que se muestra primero"
            title={available ? undefined : 'No hay tasa de cambio disponible, así que los precios solo se muestran en dólares'}
            className={cn('bg-surface-2 inline-flex items-center gap-0.5 rounded-[10px] p-[3px]', !available && 'opacity-60', className)}
        >
            {option(!isVesPrimary, 'USD', () => setDisplay('usd_primary'))}
            {option(isVesPrimary, 'Bs.', () => setDisplay('ves_primary'))}
        </div>
    );
}
