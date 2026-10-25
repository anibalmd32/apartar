import { Button } from '@/components/ui/button';
import { type LiveRate } from '@/features/catalog/hooks/useLiveRate';
import { RateStamp } from '@/features/currency/components/RateStamp';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';

interface LiveRateStampProps {
    live: LiveRate;
    timezone: string;
    className?: string;
}

export function LiveRateStamp({ live, timezone, className }: LiveRateStampProps) {
    if (live.rate.rate === null || live.rate.publishedAt === null) {
        return null;
    }

    return (
        <span className={cn('border-line inline-flex min-h-9 items-center gap-2 rounded-full border px-3 py-1', className)}>
            <span aria-hidden="true" className={cn('size-2 shrink-0 rounded-full', live.rate.isStale ? 'bg-warning' : 'bg-success')} />
            <RateStamp rate={live.rate} timezone={timezone} className="text-ink-2 font-semibold opacity-100!" />
        </span>
    );
}

export function RateChangedNotice({ live }: { live: LiveRate }) {
    if (!live.differsFromPrices) {
        return null;
    }

    return (
        <p className="bg-warning-soft text-ink-2 flex flex-wrap items-center justify-center gap-x-1 px-4 py-1.5 text-center text-xs" role="status">
            Los precios se calcularon con la tasa anterior.
            <Button variant="link" size="sm" className="h-auto min-h-0 px-1" onClick={() => router.reload()}>
                Actualizar precios
            </Button>
        </p>
    );
}
