import { Alert } from '@/components/ui/alert';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { PaymentAccountList } from '@/features/catalog/components/PaymentAccountList';
import { PaymentAccountChoice } from '@/features/receipts/components/ReceiptChoiceFields';
import { type ReceiptForm } from '@/features/receipts/hooks/useReceiptForm';
import { type PaymentAccountView } from '@/types/payment';
import { type ReceiptMethodOption } from '@/types/receipt';

interface ReceiptPaymentFieldsProps {
    form: ReceiptForm;
    paymentAccounts: readonly PaymentAccountView[];
    methods: readonly ReceiptMethodOption[];
    businessName: string;
}

function accountLabel(account: PaymentAccountView): string {
    return [account.methodLabel, account.bank, account.identifier].filter((part) => part !== null && part !== '').join(' · ');
}

export function ReceiptPaymentFields({ form, paymentAccounts, methods, businessName }: ReceiptPaymentFieldsProps) {
    const { data, errors } = form;

    return (
        <>
            {paymentAccounts.length === 0 ? (
                <Alert tone="info">Este negocio no tiene cuentas de cobro publicadas: indica en la nota a dónde pagaste.</Alert>
            ) : (
                <>
                    <PaymentAccountChoice
                        accounts={paymentAccounts}
                        value={data.paymentAccount}
                        onChange={form.selectAccount}
                        label={accountLabel}
                        error={errors.paymentAccount}
                    />

                    {form.selectedAccount !== null ? <PaymentAccountList accounts={[form.selectedAccount]} businessName={businessName} /> : null}
                </>
            )}

            <SelectField
                label="Método de pago"
                name="method"
                value={data.method}
                onChange={(event) => form.selectMethod(event.target.value)}
                options={[{ value: '', label: 'Elige cómo pagaste' }, ...methods.map((method) => ({ value: method.value, label: method.label }))]}
                error={errors.method}
                required
            />

            {form.requiresReference ? (
                <div className="grid gap-2 sm:grid-cols-2">
                    <TextField
                        label="Banco"
                        name="bank"
                        hint="Desde el que hiciste el pago."
                        value={data.bank}
                        onChange={(event) => form.setData('bank', event.target.value)}
                        error={errors.bank}
                        autoComplete="off"
                        required
                    />

                    <TextField
                        label="Número de referencia"
                        name="reference"
                        value={data.reference}
                        onChange={(event) => form.setData('reference', event.target.value)}
                        error={errors.reference}
                        autoComplete="off"
                        required
                    />
                </div>
            ) : null}
        </>
    );
}
