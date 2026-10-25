import { useCurrencyDisplay } from '@/features/currency/hooks/useCurrencyDisplay';
import { type CurrencyPageProps } from '@/types/exchange';
import { usePage } from '@inertiajs/react';

interface CurrencyToggleProps {
    className?: string;
}

export function CurrencyToggle({ className }: CurrencyToggleProps) {
    const { currency } = usePage<{ currency: CurrencyPageProps }>().props;
    const { isVesPrimary, toggle } = useCurrencyDisplay();

    const available = currency?.rate?.rate !== null && currency?.rate?.rate !== undefined;

    return (
        <button
            type="button"
            className={`btn btn-sm btn-ghost ${className ?? ''}`}
            onClick={toggle}
            disabled={!available}
            aria-pressed={isVesPrimary}
            aria-label="Cambiar la moneda que se muestra primero"
            title={
                available
                    ? 'Cambia qué moneda se muestra primero'
                    : 'No hay tasa de cambio disponible, así que los precios solo se muestran en dólares'
            }
        >
            {isVesPrimary ? 'Bs. primero' : '$ primero'}
        </button>
    );
}
