import type { CentsUsd, MoneyView } from '@/types/money';

export function centsUsd(value: number): CentsUsd {
    if (!Number.isInteger(value)) {
        throw new TypeError(`Los céntimos son enteros y llegó ${value}. Un importe fraccionario significa que alguien convirtió con coma flotante.`);
    }

    return value as CentsUsd;
}

export function hasVes(money: MoneyView): boolean {
    return money.ves !== null;
}

export function availableLabels(money: MoneyView): string[] {
    return hasVes(money) ? [money.usd, money.ves as string] : [money.usd];
}

export function amountInput(canonical: string): string {
    const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(canonical.trim());

    if (match === null) {
        return canonical;
    }

    const whole = (match[1] ?? '').replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return match[2] === undefined ? whole : `${whole},${match[2]}`;
}
