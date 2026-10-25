import { type CurrencyDisplay } from '@/types/money';

export type RateSource = 'bcv' | 'parallel' | 'manual';

export interface RateView {
    readonly rate: number | null;
    readonly source: RateSource;
    readonly sourceLabel: string;
    readonly publishedAt: string | null;
    readonly isStale: boolean;
}

export interface CurrencyPageProps {
    readonly display: CurrencyDisplay;
    readonly rate: RateView;
    readonly timezone: string;
}
