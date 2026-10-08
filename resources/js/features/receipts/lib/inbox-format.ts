import { type Currency } from '@/types/money';

/**
 * Cómo se leen las cifras y las horas de la bandeja (`REQ-PAY-004` RB-5).
 *
 * Aquí no se decide nada: si un comprobante está atrasado lo dice el servidor
 * (RB-7). Lo que se calcula es **cómo se dice** lo que ya se sabe.
 */

/**
 * El importe declarado tal como lo escribió el cliente, con su moneda:
 * `$850.00` o `Bs. 1.275,50`, como el resto de la aplicación.
 *
 * Se agrupa **sobre la cadena** y no pasando por `Number`: `declared_amount`
 * admite catorce cifras enteras, y un `double` ya no las representa todas.
 */
export function formatDeclaredAmount(amount: string, currency: Currency): string {
    const [whole = '0', fraction = ''] = amount.split('.');
    const cents = fraction.padEnd(2, '0').slice(0, 2);
    const negative = whole.startsWith('-');
    const digits = negative ? whole.slice(1) : whole;

    const grouped = (separator: string) => digits.replace(/\B(?=(\d{3})+(?!\d))/g, separator);
    const sign = negative ? '-' : '';

    return currency === 'USD' ? `${sign}$${grouped(',')}.${cents}` : `${sign}Bs. ${grouped('.')},${cents}`;
}

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

/**
 * Cuánto lleva en la bandeja: «hace 5 min», «hace 3 h», «hace 2 d».
 *
 * Es la antigüedad de RB-5, contada desde que llegó. Redondea hacia abajo,
 * como un reloj, y **cuenta en horas hasta las 48**: es el umbral de
 * atrasado (RB-7), y «hace 1 d» no dice si a un comprobante le quedan cinco
 * horas o veinte para estarlo.
 */
export function formatAge(submittedAt: string, now: number): string {
    const elapsed = Math.max(0, now - Date.parse(submittedAt));

    if (elapsed < HOUR) {
        return `hace ${Math.floor(elapsed / MINUTE)} min`;
    }

    if (elapsed < 2 * DAY) {
        return `hace ${Math.floor(elapsed / HOUR)} h`;
    }

    return `hace ${Math.floor(elapsed / DAY)} d`;
}

/** `2026-10-06` → `06/10/2026`: el día declarado, sin pasar por ninguna zona. */
export function formatDay(day: string): string {
    const [year, month, date] = day.split('-');

    return `${date}/${month}/${year}`;
}

/**
 * Una tasa como se lee: `42.50000000` → `42,50`, `36.12345679` → `36,12345679`.
 * Con dos decimales como mínimo y sin los ceros de relleno de
 * `DECIMAL(18,8)`; sobre la cadena, sin pasar por `Number`.
 */
export function formatRate(rate: string): string {
    const [whole = '0', fraction = ''] = rate.split('.');
    const trimmed = fraction.replace(/0+$/, '').padEnd(2, '0');

    return `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${trimmed}`;
}
