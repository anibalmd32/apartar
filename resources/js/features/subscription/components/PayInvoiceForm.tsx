import { BankField } from '@/components/ui/bank-field';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { ReceiptDropzone } from '@/features/subscription/components/ReceiptDropzone';
import { type InvoiceRow, type InvoicesPageProps } from '@/types/invoice';
import { useForm } from '@inertiajs/react';

type PayInvoiceFormData = {
    method: string;
    bank: string;
    reference: string;
    paidAt: string;
    amount: string;
    currency: string;
    file: File | null;
};

export function PayInvoiceForm({
    invoice,
    methods,
    currencies,
    file,
}: { invoice: InvoiceRow } & Pick<InvoicesPageProps, 'methods' | 'currencies' | 'file'>) {
    const first = methods[0];
    const pending = invoice.outstanding ?? invoice.amount;
    const { data, setData, post, processing, errors } = useForm<PayInvoiceFormData>({
        method: first?.value ?? '',
        bank: '',
        reference: '',
        paidAt: new Date().toISOString().slice(0, 10),
        amount: (pending.cents / 100).toFixed(2),
        currency: 'USD',
        file: null,
    });
    const method = methods.find((option) => option.value === data.method);

    if (invoice.payUrl === null) {
        return null;
    }

    const url = invoice.payUrl;

    return (
        <form
            className="flex flex-col gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                post(url, { forceFormData: true, preserveScroll: true });
            }}
        >
            <SelectField
                label="Cómo pagaste"
                options={methods}
                value={data.method}
                onChange={(event) => setData('method', event.target.value)}
                error={errors.method}
            />
            {method?.requiresReference ? (
                <>
                    <BankField label="Banco" value={data.bank} onChange={(value) => setData('bank', value)} error={errors.bank} />
                    <TextField
                        label="Referencia"
                        value={data.reference}
                        onChange={(event) => setData('reference', event.target.value)}
                        error={errors.reference}
                    />
                </>
            ) : null}
            <DateField label="Día del pago" value={data.paidAt} onChange={(value) => setData('paidAt', value)} error={errors.paidAt} />
            <div className="grid grid-cols-2 gap-2">
                <TextField
                    label="Importe"
                    inputMode="decimal"
                    value={data.amount}
                    onChange={(event) => setData('amount', event.target.value)}
                    error={errors.amount}
                />
                <SelectField
                    label="Moneda"
                    options={currencies}
                    value={data.currency}
                    onChange={(event) => setData('currency', event.target.value)}
                    error={errors.currency}
                />
            </div>
            <ReceiptDropzone
                label="Comprobante"
                accept={file.accept}
                hint={`Foto o PDF, hasta ${file.maxMb} MB.`}
                file={data.file}
                onFile={(picked) => setData('file', picked)}
                error={errors.file ?? (errors as Record<string, string | undefined>).invoice}
            />
            <Button type="submit" loading={processing}>
                Enviar comprobante
            </Button>
        </form>
    );
}
