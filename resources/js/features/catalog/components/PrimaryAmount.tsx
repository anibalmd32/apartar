import { useCurrencyDisplay } from '@/features/currency/hooks/useCurrencyDisplay';
import { hasVes } from '@/lib/money';
import { type MoneyView } from '@/types/money';

export function PrimaryAmount({ money, className }: { money: MoneyView; className?: string }) {
    const { isVesPrimary } = useCurrencyDisplay();

    return <span className={className}>{isVesPrimary && hasVes(money) ? money.ves : money.usd}</span>;
}
