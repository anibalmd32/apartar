import { Button } from '@/components/ui/button';
import { FileField } from '@/components/ui/file-field';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { type InvoiceRow, type InvoicesPageProps } from '@/types/invoice';
import { useForm } from '@inertiajs/react';

// Un `type` y no una `interface`: `useForm` exige un índice que una interfaz no declara.
type PayInvoiceFormData = {
    method: string;
    bank: string;
    reference: string;
    paidAt: string;
    amount: string;
    currency: string;
    file: File | null;
};

/**
 * El comprobante de una factura de la suscripción (`REQ-SUB-006` RB-1, RB-2,
 * RB-7, `T-069`): lo mismo que un cliente declara al pagarle al negocio, ahora
 * el negocio pagándole a la plataforma. Banco y referencia aparecen cuando el
 * método la exige; el importe se precarga con lo que falta, en dólares.
 *
 * No decide nada: si la factura se puede pagar lo dijo el servidor con
 * `payUrl`, y los duplicados y el tipo de archivo los vuelve a comprobar él.
 */
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
                    <TextField label="Banco" value={data.bank} onChange={(event) => setData('bank', event.target.value)} error={errors.bank} />
                    <TextField
                        label="Referencia"
                        value={data.reference}
                        onChange={(event) => setData('reference', event.target.value)}
                        error={errors.reference}
                    />
                </>
            ) : null}
            <TextField
                label="Día del pago"
                type="date"
                value={data.paidAt}
                onChange={(event) => setData('paidAt', event.target.value)}
                error={errors.paidAt}
            />
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
            <FileField
                label="Comprobante"
                accept={file.accept}
                hint={`Foto o PDF, hasta ${file.maxMb} MB.`}
                onChange={(event) => setData('file', event.target.files?.[0] ?? null)}
                error={errors.file ?? (errors as Record<string, string | undefined>).invoice}
            />
            <Button type="submit" loading={processing}>
                Enviar comprobante
            </Button>
        </form>
    );
}
