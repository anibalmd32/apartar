import { publishedAgo } from '@/features/currency/lib/rateAge';
import { cn } from '@/lib/utils';
import { type CurrencyPageProps, type RateView } from '@/types/exchange';
import { usePage } from '@inertiajs/react';

const FORMAT = new Intl.NumberFormat('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

interface RateTextProps {
    className?: string;
    compact?: boolean;
    rate?: RateView;
}

export function RateText({ className, compact = false, rate: override }: RateTextProps) {
    const { currency } = usePage<{ currency?: CurrencyPageProps }>().props;
    const rate = override ?? currency?.rate;

    if (rate === undefined || rate.rate === null) {
        return null;
    }

    return (
        <span className={cn('inline-flex min-w-0 items-baseline gap-1.5 text-[0.8125rem] whitespace-nowrap', className)}>
            <span className="text-ink-2">Dólar {rate.sourceLabel}</span>
            <span className="font-bold tabular-nums">Bs. {FORMAT.format(rate.rate)}</span>
            <span className={cn('text-xs', rate.isStale ? 'text-warning' : 'text-ink-3', compact && !rate.isStale && 'max-xl:hidden')}>
                {rate.isStale ? 'puede haber cambiado' : publishedAgo(rate.publishedAt)}
            </span>
        </span>
    );
}

export function RateStrip({ className, rate: override }: { className?: string; rate?: RateView }) {
    const { currency } = usePage<{ currency?: CurrencyPageProps }>().props;
    const rate = override ?? currency?.rate;

    if (rate === undefined || rate.rate === null) {
        return null;
    }

    return (
        <div className={cn('border-line border-t px-4 py-1.5', className)}>
            <RateText rate={rate} className="max-w-full flex-wrap" />
        </div>
    );
}
