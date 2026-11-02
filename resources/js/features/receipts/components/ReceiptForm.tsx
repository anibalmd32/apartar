import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ReceiptAmountFields } from '@/features/receipts/components/ReceiptAmountFields';
import { ReceiptNoteField } from '@/features/receipts/components/ReceiptNoteField';
import { ReceiptMethodField, ReceiptReferenceFields } from '@/features/receipts/components/ReceiptPaymentFields';
import { ReceiptUploadZone } from '@/features/receipts/components/ReceiptUploadZone';
import { type AutofilledField, type ReceiptForm as ReceiptFormState } from '@/features/receipts/hooks/useReceiptForm';
import { afterPaint, revealFirstError } from '@/lib/reveal-first-error';
import { type ReceiptCreateProps } from '@/types/receipt';
import { type FormEventHandler } from 'react';

type ReceiptFormProps = Pick<ReceiptCreateProps, 'paymentAccounts' | 'methods' | 'currencies' | 'suggestion' | 'paidAt' | 'file'> & {
    form: ReceiptFormState;
    businessName: string;
};

const AUTOFILLED_NAMES: Record<AutofilledField, string> = {
    amount: 'el monto',
    paidAt: 'la fecha',
    bank: 'el banco',
    reference: 'la referencia',
};

function listed(fields: readonly AutofilledField[]): string {
    const names = fields.map((field) => AUTOFILLED_NAMES[field]);

    return names.length === 1 ? (names[0] ?? '') : `${names.slice(0, -1).join(', ')} y ${names[names.length - 1]}`;
}

function ReadingNotice({ form }: { form: ReceiptFormState }) {
    if (form.reading === 'reading') {
        return (
            <p role="status" className="bg-surface-2 text-ink-2 flex items-center gap-2 rounded-xl p-3 text-sm">
                <span className="loading loading-spinner loading-sm" aria-hidden="true" />
                Estamos validando tu comprobante…
            </p>
        );
    }

    if (form.reading !== 'read') {
        return null;
    }

    return (
        <p role="status" className="bg-info-soft text-base-content rounded-xl p-3 text-sm">
            {form.autofilled.length > 0
                ? `Tomamos de tu captura ${listed(form.autofilled)}. Revisa que esté bien y completa lo que falte.`
                : 'Revisa los datos de tu pago y completa lo que esté vacío.'}
        </p>
    );
}

export function ReceiptForm({ form, paymentAccounts, methods, currencies, suggestion, paidAt, file }: ReceiptFormProps) {
    const { data, errors, processing } = form;
    const validating = form.reading === 'reading';

    const onSubmit: FormEventHandler = (event) => {
        event.preventDefault();

        if (!form.submit()) {
            afterPaint(() => revealFirstError(event.target as HTMLFormElement));
        }
    };

    return (
        <form className="flex flex-col gap-3" onSubmit={onSubmit} noValidate>
            {paymentAccounts.length === 0 ? <ReceiptMethodField form={form} methods={methods} /> : null}

            <ReceiptUploadZone
                label="Captura o PDF del pago"
                accept={file.accept}
                file={data.file}
                onChange={form.setFile}
                error={errors.file}
                hint={`JPG, PNG, WebP o PDF, hasta ${file.maxMb} MB.`}
            />

            {form.fileTooLarge ? (
                <Alert tone="warning">
                    Este archivo pasa de {file.maxMb} MB y no lo vamos a poder recibir. Prueba con una captura de pantalla o un PDF más liviano.
                </Alert>
            ) : null}

            <ReadingNotice form={form} />

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
