import { type MoneyView } from '@/types/money';

export function paidOnly(money: MoneyView): MoneyView {
    return { ...money, ves: null };
}
