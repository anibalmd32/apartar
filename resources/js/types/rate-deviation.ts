export interface RateDeviationView {
    readonly token: string;
    readonly percent: number;
    readonly above: boolean;
    readonly manual: string;
    readonly reference: string;
    readonly sourceLabel: string;
}

export interface RateChecks {
    readonly staleRate: { readonly token: string; readonly days: number; readonly sourceLabel: string } | null;
    readonly rateDeviation?: RateDeviationView | null;
}
