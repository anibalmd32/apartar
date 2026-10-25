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
