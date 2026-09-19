import { useCurrencyDisplay } from '@/features/currency/hooks/useCurrencyDisplay';
import { hasVes } from '@/lib/money';
import { type MoneyView } from '@/types/money';

/**
 * Un importe, con la moneda que la persona prefiere delante
 * (`REQ-FX-004` RB-1 y RB-2).
 *
 * `$20.00 (Bs. 850,00)` en modo `usd_primary` y `Bs. 850,00 ($20.00)` en
 * `ves_primary`. Las dos cadenas **vienen hechas del servidor**: aquí no se
 * formatea nada, solo se elige el orden. Un `.toFixed(2)` escrito en este
 * archivo puntuaría las dos monedas al revés la una de la otra y el resultado
 * se leería como un precio correcto.
 *
 * ## Sin tasa, solo el dólar
 *
 * `REQ-FX-005` RB-4: el monto en bolívares se **oculta por completo**. Ni
 * `Bs. 0,00` ni un guion ni «no disponible» donde va una cifra — las tres
 * cosas ponen en pantalla un precio que nadie ha calculado. `hasVes()` es la
 * única pregunta, y está escrita una sola vez en `lib/money.ts` para que
 * ninguna pantalla la resuelva con un `money.ves || '—'`.
 */

interface MoneyLabelProps {
    money: MoneyView;
    /** Clase del importe destacado, por si la pantalla lo quiere más grande. */
    className?: string;
}

export function MoneyLabel({ money, className }: MoneyLabelProps) {
    const { isVesPrimary } = useCurrencyDisplay();

    if (!hasVes(money)) {
        return <span className={className}>{money.usd}</span>;
    }

    const primary = isVesPrimary ? (money.ves as string) : money.usd;
    const secondary = isVesPrimary ? money.usd : (money.ves as string);

    return (
        <span className={className}>
            {primary} <span className="opacity-70">({secondary})</span>
        </span>
    );
}
