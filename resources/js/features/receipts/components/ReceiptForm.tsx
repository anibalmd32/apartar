import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FileField } from '@/components/ui/file-field';
import { ReceiptAmountFields } from '@/features/receipts/components/ReceiptAmountFields';
import { ReceiptFilePreview } from '@/features/receipts/components/ReceiptFilePreview';
import { ReceiptNoteField } from '@/features/receipts/components/ReceiptNoteField';
import { ReceiptPaymentFields, ReceiptReferenceFields } from '@/features/receipts/components/ReceiptPaymentFields';
import { type ReceiptForm as ReceiptFormState } from '@/features/receipts/hooks/useReceiptForm';
import { type ReceiptCreateProps } from '@/types/receipt';
import { type FormEventHandler } from 'react';

type ReceiptFormProps = Pick<ReceiptCreateProps, 'paymentAccounts' | 'methods' | 'currencies' | 'suggestion' | 'paidAt' | 'file'> & {
    form: ReceiptFormState;
    businessName: string;
};

export function ReceiptForm({ form, paymentAccounts, methods, currencies, suggestion, paidAt, file, businessName }: ReceiptFormProps) {
    const { data, errors, processing } = form;
    const validating = form.reading === 'reading';

    const onSubmit: FormEventHandler = (event) => {
        event.preventDefault();
        form.submit();
    };

    return (
        <form className="flex flex-col gap-3" onSubmit={onSubmit} noValidate>
            <ReceiptPaymentFields form={form} paymentAccounts={paymentAccounts} methods={methods} businessName={businessName} />

            <FileField
                label="Captura o PDF del pago"
                name="file"
                accept={file.accept}
                onChange={(event) => form.setFile(event.target.files?.[0] ?? null)}
                error={errors.file}
                hint={`JPG, PNG, WebP o PDF, hasta ${file.maxMb} MB.`}
                required
            />

            <ReceiptFilePreview file={data.file} />

            {form.fileTooLarge ? (
                <Alert tone="warning">
                    Este archivo pasa de {file.maxMb} MB y no lo vamos a poder recibir. Prueba con una captura de pantalla o un PDF más liviano.
                </Alert>
            ) : null}

            {validating ? (
                <p role="status" className="bg-surface-2 text-ink-2 flex items-center gap-2 rounded-xl p-3 text-sm">
                    <span className="loading loading-spinner loading-sm" aria-hidden="true" />
                    Estamos validando tu comprobante…
                </p>
            ) : null}

            {form.reading === 'read' ? (
                <p role="status" className="text-ink-2 text-sm">
                    Revisa los datos de tu pago y completa lo que esté vacío.
                </p>
            ) : null}

            <fieldset disabled={validating} aria-busy={validating} className="flex min-w-0 flex-col gap-3 disabled:opacity-60">
                <ReceiptReferenceFields form={form} />

                <ReceiptAmountFields form={form} currencies={currencies} suggestion={suggestion} paidAt={paidAt} />

                <ReceiptNoteField value={data.note} onChange={(value) => form.setData('note', value)} error={errors.note} />
            </fieldset>

            <Button type="submit" variant="primary" size="lg" block loading={processing} disabled={validating} className="mt-2">
                {processing ? 'Enviando…' : 'Enviar comprobante'}
            </Button>
        </form>
    );
}
