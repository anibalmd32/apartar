import { DateField } from '@/components/ui/date-field';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { RateField } from '@/features/receipts/components/RateField';
import { useRateOfDay } from '@/features/receipts/hooks/useRateOfDay';
import { type PaymentFieldErrors, type PaymentFieldValues } from '@/features/receipts/lib/payment-fields';
import { completeAmount, typedAmount } from '@/lib/amount-typing';
import { type PaymentFieldOptions } from '@/types/layaway-backoffice';
import { useState } from 'react';

const CURRENCY_SYMBOLS: Readonly<Record<string, string>> = { USD: '$', VES: 'Bs.' };

interface PaymentAmountFieldsProps {
    values: PaymentFieldValues;
    errors: PaymentFieldErrors;
    onChange: (patch: Partial<PaymentFieldValues>) => void;
    options: PaymentFieldOptions;
}

export function PaymentAmountFields({ values, errors, onChange, options }: PaymentAmountFieldsProps) {
    const [manualRate, setManualRate] = useState(values.rate !== '');
    const rateOfDay = useRateOfDay(options.rateOfDayUrl, values.paid_at, values.currency === 'VES');

    return (
        <>
            <SelectField
                label="Moneda"
                name="currency"
                options={options.currencies}
                value={values.currency}
                onChange={(event) => onChange({ currency: event.target.value, rate: event.target.value === 'USD' ? '' : values.rate })}
                error={errors.currency}
            />
            <TextField
                label="Importe"
                name="amount"
                inputMode="decimal"
                autoComplete="off"
                placeholder="0,00"
                prefix={CURRENCY_SYMBOLS[values.currency] ?? values.currency}
                className="tabular-nums"
                value={values.amount}
                onChange={(event) => onChange({ amount: typedAmount(event) })}
                onBlur={() => {
                    const completed = completeAmount(values.amount);

                    if (completed !== values.amount) {
                        onChange({ amount: completed });
                    }
                }}
                error={errors.amount}
            />
            <DateField
                label="Fecha del pago"
                name="paid_at"
                min={options.paidAt.min}
                max={options.paidAt.max}
                value={values.paid_at}
                onChange={(value) => onChange({ paid_at: value })}
                error={errors.paid_at}
            />
            {values.currency === 'VES' ? (
                <RateField
                    value={values.rate}
                    onChange={(rate) => onChange({ rate })}
                    manual={manualRate}
                    onManualChange={setManualRate}
                    day={values.paid_at}
                    today={options.paidAt.max}
                    ofDay={rateOfDay}
                    error={errors.rate}
                />
            ) : null}
        </>
    );
}
