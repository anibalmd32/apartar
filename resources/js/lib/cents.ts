const amount = new Intl.NumberFormat('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function formatUsdCents(cents: number): string {
    return `$${amount.format(cents / 100)}`;
}

export function formatBsCents(cents: number, rate: number): string {
    return `Bs. ${amount.format(Math.round(cents * (Math.round(rate * 100) / 100)) / 100)}`;
}
