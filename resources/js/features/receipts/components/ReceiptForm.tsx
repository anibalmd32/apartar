import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FileField } from '@/components/ui/file-field';
import { ReceiptAmountFields } from '@/features/receipts/components/ReceiptAmountFields';
import { ReceiptFilePreview } from '@/features/receipts/components/ReceiptFilePreview';
import { ReceiptNoteField } from '@/features/receipts/components/ReceiptNoteField';
import { ReceiptPaymentFields } from '@/features/receipts/components/ReceiptPaymentFields';
import { type ReceiptForm as ReceiptFormState } from '@/features/receipts/hooks/useReceiptForm';
import { type ReceiptCreateProps } from '@/types/receipt';
import { type FormEventHandler } from 'react';

/**
 * El formulario del comprobante (`REQ-PAY-001`), en el orden en que se tiene
 * la información delante: a dónde y cómo se pagó, cuándo y cuánto, una nota y
 * la captura.
 *
 * `noValidate`: quien decide es el servidor, y **cada rechazo aterriza en su
 * campo** —también el duplicado de `REQ-PAY-003` («Este comprobante ya fue
 * reportado.»), que llega en `file` o en `reference`—. Inertia conserva lo
 * escrito, la captura incluida, así que corregir no obliga a empezar de nuevo.
 *
 * El aviso de tamaño es local y no bloquea: lo explica `useReceiptForm`.
 */

type ReceiptFormProps = Pick<ReceiptCreateProps, 'paymentAccounts' | 'methods' | 'currencies' | 'suggestion' | 'paidAt' | 'file'> & {
    form: ReceiptFormState;
    businessName: string;
};

export function ReceiptForm({ form, paymentAccounts, methods, currencies, suggestion, paidAt, file, businessName }: ReceiptFormProps) {
    const { data, errors, processing } = form;

    const onSubmit: FormEventHandler = (event) => {
        event.preventDefault();
        form.submit();
    };

    return (
        <form className="flex flex-col gap-2" onSubmit={onSubmit} noValidate>
            <ReceiptPaymentFields form={form} paymentAccounts={paymentAccounts} methods={methods} businessName={businessName} />

            <ReceiptAmountFields form={form} currencies={currencies} suggestion={suggestion} paidAt={paidAt} />

            <ReceiptNoteField value={data.note} onChange={(value) => form.setData('note', value)} error={errors.note} />

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

            <Button type="submit" variant="primary" block loading={processing} className="mt-2">
                {processing ? 'Enviando…' : 'Enviar comprobante'}
            </Button>
        </form>
    );
}
