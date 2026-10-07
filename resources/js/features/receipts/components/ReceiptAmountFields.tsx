import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type ReceiptForm } from '@/features/receipts/hooks/useReceiptForm';
import { type ReceiptCurrencyOption, type ReceiptSuggestion } from '@/types/receipt';

/**
 * Cuándo y cuánto se pagó (`REQ-PAY-001` RB-4, RB-5, RB-9, SP-5, SP-10).
 *
 * La fecha está acotada a lo que el servidor acepta —desde que se creó el
 * apartado hasta hoy en la zona del negocio— y arranca en hoy, que es cuando
 * casi todo el mundo reporta. El selector de fecha no deja elegir fuera del
 * rango; si se escribe a mano, el `422` llega a este mismo campo.
 *
 * El importe es texto con teclado decimal y no `type="number"`: el navegador
 * reinterpreta la coma y el punto según su idioma, y una cifra de dinero no
 * puede cambiar por eso. La sugerencia se dice debajo como ayuda (RB-9).
 */

interface ReceiptAmountFieldsProps {
    form: ReceiptForm;
    currencies: readonly ReceiptCurrencyOption[];
    suggestion: ReceiptSuggestion | null;
    paidAt: { readonly min: string; readonly max: string };
}

function SuggestionHint({ suggestion }: { suggestion: ReceiptSuggestion }) {
    const what = suggestion.kind === 'down_payment' ? 'la inicial' : `la cuota ${suggestion.installmentNumber ?? ''}`.trim();

    return (
        <>
            Te sugerimos {what}: <MoneyLabel money={suggestion.amount} />. Si pagaste otro monto, escríbelo.
        </>
    );
}

export function ReceiptAmountFields({ form, currencies, suggestion, paidAt }: ReceiptAmountFieldsProps) {
    const { data, errors } = form;

    return (
        <>
            <TextField
                label="Fecha del pago"
                name="paidAt"
                type="date"
                min={paidAt.min}
                max={paidAt.max}
                value={data.paidAt}
                onChange={(event) => form.setData('paidAt', event.target.value)}
                error={errors.paidAt}
                required
            />

            <div className="grid grid-cols-[minmax(0,1fr)_7rem] gap-2">
                <TextField
                    label="Monto que pagaste"
                    name="amount"
                    inputMode="decimal"
                    autoComplete="off"
                    placeholder="0.00"
                    value={data.amount}
                    onChange={(event) => form.setAmount(event.target.value)}
                    error={errors.amount}
                    required
                />

                <SelectField
                    label="Moneda"
                    name="currency"
                    value={data.currency}
                    onChange={(event) => form.selectCurrency(event.target.value)}
                    options={currencies}
                    error={errors.currency}
                    required
                />
            </div>

            {suggestion !== null ? (
                <p className="text-sm opacity-80">
                    <SuggestionHint suggestion={suggestion} />
                </p>
            ) : null}
        </>
    );
}
