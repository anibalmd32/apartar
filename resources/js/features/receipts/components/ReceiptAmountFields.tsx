import { DateField } from '@/components/ui/date-field';
import { TextField } from '@/components/ui/text-field';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { suggestionWhat } from '@/features/layaway/lib/next-payment';
import { CurrencyChoice } from '@/features/receipts/components/ReceiptChoiceFields';
import { type ReceiptForm } from '@/features/receipts/hooks/useReceiptForm';
import { typedAmount } from '@/lib/amount-typing';
import { type ReceiptCurrencyOption, type ReceiptSuggestion } from '@/types/receipt';

interface ReceiptAmountFieldsProps {
    form: ReceiptForm;
    currencies: readonly ReceiptCurrencyOption[];
    suggestion: ReceiptSuggestion | null;
    paidAt: { readonly min: string; readonly max: string };
}

const CURRENCY_SYMBOLS: Readonly<Record<string, string>> = { USD: '$', VES: 'Bs.' };

function SuggestionHint({ suggestion }: { suggestion: ReceiptSuggestion }) {
    const lead = suggestion.kind === 'overdue' ? 'Para ponerte al día te sugerimos' : 'Te sugerimos';

    return (
        <>
            {lead} {suggestionWhat(suggestion)}: <MoneyLabel money={suggestion.amount} />. Si pagaste otro monto, escríbelo.
        </>
    );
}

export function ReceiptAmountFields({ form, currencies, suggestion, paidAt }: ReceiptAmountFieldsProps) {
    const { data, errors } = form;

    return (
        <>
            <DateField
                label="Fecha del pago"
                name="paidAt"
                min={paidAt.min}
                max={paidAt.max}
                value={data.paidAt}
                onChange={(value) => form.setData('paidAt', value)}
                error={errors.paidAt}
                required
            />

            <CurrencyChoice currencies={currencies} value={data.currency} onChange={form.selectCurrency} error={errors.currency} />

            <TextField
                label="Monto que pagaste"
                name="amount"
                inputMode="decimal"
                autoComplete="off"
                placeholder="0,00"
                prefix={CURRENCY_SYMBOLS[data.currency] ?? data.currency}
                className="tabular-nums"
                value={data.amount}
                onChange={(event) => form.setAmount(typedAmount(event))}
                error={errors.amount}
                required
            />

            {suggestion !== null ? (
                <p className="text-sm opacity-80">
                    <SuggestionHint suggestion={suggestion} />
                </p>
            ) : null}
        </>
    );
}
