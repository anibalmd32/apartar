import { publishedStamp } from '@/features/currency/lib/rateAge';
import { type RateView } from '@/types/exchange';

interface RateStampProps {
    rate: RateView;
    timezone: string;
    className?: string;
}

export function RateStamp({ rate, timezone, className }: RateStampProps) {
    const stamp = publishedStamp(rate.publishedAt, timezone);

    if (rate.rate === null || stamp === null) {
        return null;
    }

    return (
        <span className={`text-xs opacity-70 ${className ?? ''}`}>
            Tasa {rate.sourceLabel} del {stamp}
            {rate.isStale ? <span className="ml-1 opacity-100"> · puede estar desactualizada</span> : null}
        </span>
    );
}
