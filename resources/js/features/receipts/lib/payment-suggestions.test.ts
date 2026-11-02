import { centsAmountInput, paymentSuggestions } from '@/features/receipts/lib/payment-suggestions';
import { centsUsd } from '@/lib/money';
import { type BackofficeInstallmentRow } from '@/types/layaway-backoffice';
import { expect, it } from 'vitest';

const money = (cents: number) => ({ cents: centsUsd(cents), usd: '', ves: null });

const installment = (number: number, amount: number, paid: number): BackofficeInstallmentRow => ({
    number,
    dueDate: '2026-11-10',
    dueDateLabel: '10 nov 2026',
    amount: money(amount),
    paid: money(paid),
    statusLabel: 'Pendiente',
    reschedule: null,
});

it('escribe los céntimos al estilo venezolano', () => {
    expect(centsAmountInput(1_450)).toBe('14,50');
    expect(centsAmountInput(123_405)).toBe('1.234,05');
});

it('sugiere lo que falta de la próxima cuota y el saldo', () => {
    const suggestions = paymentSuggestions({ downPaymentProgress: null, remaining: money(5_800) }, [
        installment(1, 1_450, 1_450),
        installment(2, 1_450, 0),
        installment(3, 1_450, 0),
    ]);

    expect(suggestions.map((item) => item.label)).toEqual(['Cuota 2 · $14,50', 'Saldo · $58,00']);
    expect(suggestions[0]?.amount).toBe('14,50');
});

it('sugiere la inicial pendiente antes que las cuotas y no repite el saldo si es igual', () => {
    const pending = paymentSuggestions({ downPaymentProgress: { paid: money(500), missing: money(1_500) }, remaining: money(6_000) }, [
        installment(1, 3_000, 0),
    ]);
    const last = paymentSuggestions({ downPaymentProgress: null, remaining: money(1_000) }, [installment(1, 3_000, 2_000)]);

    expect(pending.map((item) => item.label)).toEqual(['Inicial · $15,00', 'Saldo · $60,00']);
    expect(last.map((item) => item.label)).toEqual(['Cuota 1 · $10,00']);
});
