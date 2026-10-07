import { Alert } from '@/components/ui/alert';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { PaymentAccountList } from '@/features/catalog/components/PaymentAccountList';
import { type ReceiptForm } from '@/features/receipts/hooks/useReceiptForm';
import { type PaymentAccountView } from '@/types/payment';
import { type ReceiptMethodOption } from '@/types/receipt';

/**
 * A dónde y cómo se pagó: cuenta de cobro, método, banco y referencia
 * (`REQ-PAY-001` RB-2, RB-3, SP-8).
 *
 * La cuenta se nombra como en el happy path —«Pago móvil · Banesco ·
 * 0414-1234567»— y, elegida, se enseña entera con la misma tarjeta que el
 * detalle del apartado: es la forma de comprobar que es aquella a la que se
 * pagó. Sin cuentas publicadas (SP-8) el campo no existe y se pide decirlo en
 * la nota.
 *
 * Banco y referencia solo aparecen con un método que los pide; el hook los
 * vacía al pasar a otro.
 */

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
                    <SelectField
                        label="Cuenta a la que pagaste"
                        name="paymentAccount"
                        value={data.paymentAccount === null ? '' : String(data.paymentAccount)}
                        onChange={(event) => form.selectAccount(event.target.value)}
                        options={[
                            { value: '', label: 'Elige la cuenta' },
                            ...paymentAccounts.map((account) => ({ value: String(account.id), label: accountLabel(account) })),
                        ]}
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
