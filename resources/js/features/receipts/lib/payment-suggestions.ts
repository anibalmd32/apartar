import { amountInput } from '@/lib/money';
import { type BackofficeInstallmentRow, type BackofficeLayaway } from '@/types/layaway-backoffice';

export interface PaymentSuggestion {
    readonly key: string;
    readonly label: string;
    readonly amount: string;
}

export function centsAmountInput(cents: number): string {
    return amountInput(`${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`);
}

function suggestion(key: string, name: string, cents: number): PaymentSuggestion {
    const amount = centsAmountInput(cents);

    return { key, label: `${name} · $${amount}`, amount };
}

export function paymentSuggestions(
    layaway: Pick<BackofficeLayaway, 'downPaymentProgress' | 'remaining'>,
    installments: readonly BackofficeInstallmentRow[],
): PaymentSuggestion[] {
    const suggestions: PaymentSuggestion[] = [];
    const missingDownPayment = layaway.downPaymentProgress?.missing.cents ?? 0;
    const next = installments.find((installment) => installment.amount.cents > installment.paid.cents);

    if (missingDownPayment > 0) {
        suggestions.push(suggestion('down-payment', 'Inicial', missingDownPayment));
    } else if (next !== undefined) {
        suggestions.push(suggestion(`installment-${next.number}`, `Cuota ${next.number}`, next.amount.cents - next.paid.cents));
    }

    const remaining = layaway.remaining.cents;

    if (remaining > 0 && suggestions.every((item) => item.amount !== centsAmountInput(remaining))) {
        suggestions.push(suggestion('balance', 'Saldo', remaining));
    }

    return suggestions;
}
