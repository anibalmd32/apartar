import { useCurrencyDisplay } from '@/features/currency/hooks/useCurrencyDisplay';
import { hasVes } from '@/lib/money';
import { cn } from '@/lib/utils';
import { type MoneyView } from '@/types/money';

interface MoneyLabelProps {
    money: MoneyView;
    className?: string;
    stacked?: boolean;
    compact?: boolean;
    secondaryClassName?: string;
}

export function MoneyLabel({ money, className, stacked = false, compact = false, secondaryClassName }: MoneyLabelProps) {
    const { isVesPrimary } = useCurrencyDisplay();

    if (!hasVes(money)) {
        return <span className={className}>{money.usd}</span>;
    }

    const primary = isVesPrimary ? (money.ves as string) : money.usd;
    const secondary = isVesPrimary ? money.usd : (money.ves as string);

    if (compact) {
        return (
            <span className={className} title={secondary}>
                {primary}
                <span className="sr-only"> ({secondary})</span>
            </span>
        );
    }

    if (stacked) {
        return (
            <span className="flex flex-col gap-1">
                <span className={className}>{primary}</span>
                <span className="text-ink-3 text-xs font-normal tracking-normal">{secondary}</span>
            </span>
        );
    }

    return (
        <span className={className}>
            {primary} <span className={cn('opacity-70', secondaryClassName)}>({secondary})</span>
        </span>
    );
}
