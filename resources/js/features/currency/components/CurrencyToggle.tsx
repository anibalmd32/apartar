import { useCurrencyDisplay } from '@/features/currency/hooks/useCurrencyDisplay';
import { type CurrencyPageProps } from '@/types/exchange';
import { usePage } from '@inertiajs/react';

/**
 * El interruptor que cambia qué moneda va primero (`REQ-FX-004` RB-3).
 *
 * Afecta a **toda** la aplicación de forma consistente —catálogo, portal,
 * feed, cuotas, saldos e historial— porque la preferencia vive en el hook y no
 * en cada pantalla, y no genera ninguna petición (RB-6).
 *
 * ## Sin tasa se deshabilita, **y no desaparece** (SP-2)
 *
 * Es la diferencia entre un control apagado y un control que no está: el
 * primero se explica solo con su tooltip —«no hay tasa disponible»— y el
 * segundo deja a la persona buscando qué hizo mal o si la página está rota.
 * La regla que hay detrás es `REQ-FX-005` RB-4: sin tasa no hay bolívares que
 * enseñar, así que cambiar de orden no tendría nada que ordenar.
 */

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
