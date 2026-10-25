export type CurrencyDisplay = 'usd_primary' | 'ves_primary';

declare const centsUsdBrand: unique symbol;

export type CentsUsd = number & { readonly [centsUsdBrand]: true };

export interface MoneyView {
    readonly cents: CentsUsd;
    readonly usd: string;
    readonly ves: string | null;
}

export type Currency = 'USD' | 'VES';
