/**
 * Las cuentas del editor del reparto (`REQ-PAY-009` RB-8), **en centavos
 * enteros**: se teclea en dólares con punto y se suma sin coma flotante, como
 * `Money` en el servidor. La decisión es del servidor; esto solo avisa antes
 * de enviar.
 */

const AMOUNT = /^(\d{1,14})(?:\.(\d{1,2}))?$/;

/** «30», «30.5» o «30.50» en centavos; `null` si no es un importe. Vacío es cero. */
export function parseCents(text: string): number | null {
    const trimmed = text.trim();

    if (trimmed === '') {
        return 0;
    }

    const match = AMOUNT.exec(trimmed);

    if (match === null) {
        return null;
    }

    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
}

/** Como `MoneyFormatter`: «$1,234.56». */
export function formatCents(cents: number): string {
    const units = Math.floor(Math.abs(cents) / 100);
    const fraction = String(Math.abs(cents) % 100).padStart(2, '0');

    return `${cents < 0 ? '-' : ''}$${String(units).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${fraction}`;
}

/** Lo que se escribe en el campo: «30.00». */
export function centsToInput(cents: number): string {
    return `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`;
}

export interface AllocationRow {
    readonly installmentId: number;
    readonly number: number;
    /** Lo que admite de este pago: su importe menos lo que le pagaron los demás. */
    readonly roomCents: number;
    readonly text: string;
}

/** Por qué no se puede enviar todavía, o `null`. */
export function allocationProblem(rows: readonly AllocationRow[], expectedCents: number): string | null {
    let total = 0;

    for (const row of rows) {
        const cents = parseCents(row.text);

        if (cents === null) {
            return `El importe de la cuota ${row.number} no es válido: usa punto y hasta dos decimales.`;
        }

        if (cents > row.roomCents) {
            return `La cuota ${row.number} admite como mucho ${formatCents(row.roomCents)} de este pago.`;
        }

        total += cents;
    }

    if (total !== expectedCents) {
        const difference = formatCents(Math.abs(total - expectedCents));

        return total > expectedCents
            ? `Sobran ${difference}: el reparto tiene que sumar ${formatCents(expectedCents)}.`
            : `Faltan ${difference}: el reparto tiene que sumar ${formatCents(expectedCents)}.`;
    }

    return null;
}
