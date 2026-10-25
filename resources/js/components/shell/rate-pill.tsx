import { publishedAgo } from '@/features/currency/lib/rateAge';
import { cn } from '@/lib/utils';
import { type CurrencyPageProps } from '@/types/exchange';
import { usePage } from '@inertiajs/react';

const FORMAT = new Intl.NumberFormat('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function RatePill({ className }: { className?: string }) {
    const { currency } = usePage<{ currency?: CurrencyPageProps }>().props;
    const rate = currency?.rate;

    if (rate?.rate === null || rate?.rate === undefined) {
        return null;
    }

    return (
        <span
            className={cn('border-line inline-flex h-9 items-center gap-2 rounded-full border px-3 text-[13px] whitespace-nowrap', className)}
            title={rate.isStale ? 'La tasa puede estar desactualizada' : undefined}
        >
            <span aria-hidden="true" className={cn('size-2 rounded-full', rate.isStale ? 'bg-warning' : 'bg-success')} />
            <span className="font-semibold">
                {rate.sourceLabel} Bs. {FORMAT.format(rate.rate)}
            </span>
            <span className="text-ink-3 text-xs">
                {publishedAgo(rate.publishedAt)}
                {rate.isStale ? <span className="sr-only"> · desactualizada</span> : null}
            </span>
        </span>
    );
}
