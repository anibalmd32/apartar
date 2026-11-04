import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { cn } from '@/lib/utils';
import { type MoneyView } from '@/types/money';

interface AmountProgressProps {
    paid: MoneyView;
    total: MoneyView;
    progress: number;
    className?: string;
}

export function AmountProgress({ paid, total, progress, className }: AmountProgressProps) {
    const percent = Math.max(0, Math.min(100, progress));

    return (
        <div className={cn('flex min-w-36 flex-col gap-1', className)}>
            <div
                className="bg-base-300 h-1.5 w-full overflow-hidden rounded-full"
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent}
                aria-label={`Pagado ${percent}%`}
            >
                <div className="bg-success h-full rounded-full" style={{ width: `${percent}%` }} />
            </div>
            <span className="text-ink-3 flex items-center gap-1 text-xs whitespace-nowrap">
                <MoneyLabel money={paid} compact className="text-ink-2 font-semibold" />
                de
                <MoneyLabel money={total} compact />
                <span>· {percent}%</span>
            </span>
        </div>
    );
}
