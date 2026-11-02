import { BankField } from '@/components/ui/bank-field';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { type ReceiptForm } from '@/features/receipts/hooks/useReceiptForm';
import { type ReceiptMethodOption } from '@/types/receipt';

interface ReceiptMethodFieldProps {
    form: ReceiptForm;
    methods: readonly ReceiptMethodOption[];
}

export function ReceiptMethodField({ form, methods }: ReceiptMethodFieldProps) {
    return (
        <SelectField
            label="Método de pago"
            name="method"
            value={form.data.method}
            onChange={(event) => form.selectMethod(event.target.value)}
            options={[{ value: '', label: 'Elige cómo pagaste' }, ...methods.map((method) => ({ value: method.value, label: method.label }))]}
            error={form.errors.method}
            required
        />
    );
}

export function ReceiptReferenceFields({ form }: { form: ReceiptForm }) {
    const { data, errors } = form;

    return form.requiresReference ? (
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <BankField
                label="Banco"
                name="bank"
                hint="Desde el que hiciste el pago."
                value={data.bank}
                onChange={(value) => {
                    form.setData('bank', value);
                    form.clearErrors('bank');
                }}
                error={errors.bank}
                required
            />

            <TextField
                label="Número de referencia"
                name="reference"
                value={data.reference}
                onChange={(event) => {
                    form.setData('reference', event.target.value);
                    form.clearErrors('reference');
                }}
                error={errors.reference}
                inputMode={form.numericReference ? 'numeric' : 'text'}
                autoComplete="off"
                spellCheck={false}
                required
            />
        </div>
    ) : null;
}
