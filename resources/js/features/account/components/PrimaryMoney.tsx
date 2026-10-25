import { useCurrencyDisplay } from '@/features/currency/hooks/useCurrencyDisplay';
import { hasVes } from '@/lib/money';
import { type MoneyView } from '@/types/money';

export function PrimaryMoney({ money, className }: { money: MoneyView; className?: string }) {
    const { isVesPrimary } = useCurrencyDisplay();

    if (!hasVes(money)) {
        return <span className={className}>{money.usd}</span>;
    }

    return (
        <span className={className} title={isVesPrimary ? money.usd : (money.ves as string)}>
            {isVesPrimary ? money.ves : money.usd}
        </span>
    );
}
