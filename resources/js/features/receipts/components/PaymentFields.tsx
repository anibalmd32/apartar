import { Alert } from '@/components/ui/alert';
import { BankField } from '@/components/ui/bank-field';
import { CheckboxField } from '@/components/ui/checkbox-field';
import { FileField } from '@/components/ui/file-field';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { PaymentAmountFields } from '@/features/receipts/components/PaymentAmountFields';
import { PaymentNoteField, ReadingStatus } from '@/features/receipts/components/PaymentFieldsExtras';
import { ReceiptFilePreview } from '@/features/receipts/components/ReceiptFilePreview';
import { RateConfirmations } from '@/features/receipts/components/StaleRateConfirmation';
import { SurplusDestinationFields } from '@/features/receipts/components/SurplusDestinationFields';
import { type ApprovalPreviewState } from '@/features/receipts/hooks/useApprovalPreview';
import { useReceiptReading } from '@/features/receipts/hooks/useReceiptReading';
import {
    fileTooLarge,
    patchFromReading,
    type PaymentFieldErrors,
    type PaymentFieldValues,
    requiresReference,
} from '@/features/receipts/lib/payment-fields';
import { appliedRateLine } from '@/features/receipts/lib/payment-rate';
import { cn } from '@/lib/utils';
import { type PaymentFieldOptions } from '@/types/layaway-backoffice';
import { useEffect, useRef } from 'react';

interface PaymentFieldsProps {
    values: PaymentFieldValues;
    errors: PaymentFieldErrors;
    onChange: (patch: Partial<PaymentFieldValues>) => void;
    options: PaymentFieldOptions;
    preview: ApprovalPreviewState;
    onReadingChange?: (reading: boolean) => void;
}

export function PaymentFields({ values, errors, onChange, options, preview: { preview, error, loading }, onReadingChange }: PaymentFieldsProps) {
    const excess = preview?.unallocated ?? null;
    const confirmed = preview !== null && excess !== null && values.confirm_excess === preview.unallocatedCents;
    const withReference = requiresReference(options, values.method);
    const latest = useRef(values);
    latest.current = values;
    const reading = useReceiptReading(options.readUrl, (result) => onChange(patchFromReading(options, latest.current, result)));
    const validating = reading.state === 'reading';

    useEffect(() => onReadingChange?.(validating), [onReadingChange, validating]);

    const chooseFile = (file: File | null) => {
        onChange({ file });
        reading.read(fileTooLarge(options, file) ? null : file);
    };

    const chooseMethod = (value: string) => {
        const method = options.methods.find((option) => option.value === value);

        onChange({
            method: value,
            currency: method?.defaultCurrency ?? values.currency,
            rate: method?.defaultCurrency === 'USD' ? '' : values.rate,
            ...(method?.requiresReference ? {} : { bank: '', reference: '' }),
        });
    };

    return (
        <>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <SelectField
                    label="Método"
                    name="method"
                    options={[{ value: '', label: 'Elige cómo te pagaron' }, ...options.methods.map(({ value, label }) => ({ value, label }))]}
                    value={values.method}
                    onChange={(event) => chooseMethod(event.target.value)}
                    error={errors.method}
                />
                <SelectField
                    label="Cuenta de cobro (opcional)"
                    name="payment_account"
                    options={[
                        { value: '', label: 'Ninguna' },
                        ...options.paymentAccounts.map((account) => ({
                            value: String(account.id),
                            label: `${account.methodLabel} · ${account.identifier}${account.isActive ? '' : ' (desactivada)'}`,
                        })),
                    ]}
                    value={values.payment_account}
                    onChange={(event) => onChange({ payment_account: event.target.value })}
                    error={errors.payment_account}
                />
            </div>

            <FileField
                label="Comprobante (opcional)"
                name="file"
                accept={options.file.accept}
                onChange={(event) => chooseFile(event.target.files?.[0] ?? null)}
                error={errors.file}
                hint={`Captura o PDF del pago: JPG, PNG, WebP o PDF, hasta ${options.file.maxMb} MB.`}
            />

            <ReceiptFilePreview file={values.file} />

            {fileTooLarge(options, values.file) ? (
                <Alert tone="warning">Este archivo pasa de {options.file.maxMb} MB. Prueba con una captura de pantalla o un PDF más liviano.</Alert>
            ) : null}

            <ReadingStatus state={reading.state} />

            <fieldset disabled={validating} aria-busy={validating} className="grid min-w-0 grid-cols-1 gap-2 disabled:opacity-60 sm:grid-cols-2">
                {withReference ? (
                    <>
                        <BankField
                            label="Banco"
                            name="bank"
                            hint="Desde el que te pagaron."
                            value={values.bank}
                            onChange={(value) => onChange({ bank: value })}
                            error={errors.bank}
                            required
                        />
                        <TextField
                            label="Número de referencia"
                            name="reference"
                            value={values.reference}
                            onChange={(event) => onChange({ reference: event.target.value })}
                            error={errors.reference}
                            inputMode="numeric"
                            autoComplete="off"
                            required
                        />
                    </>
                ) : null}
                <PaymentAmountFields values={values} errors={errors} onChange={onChange} options={options} />
            </fieldset>

            <PaymentNoteField value={values.note} error={errors.note} onChange={(note) => onChange({ note })} />

            <section
                aria-label="Vista previa del pago"
                aria-busy={loading}
                className={cn('rounded-xl p-3.5 text-[13px]', preview !== null && error === null ? 'bg-success-soft' : 'bg-surface-2')}
            >
                {error !== null ? (
                    <p className="text-error">{error}</p>
                ) : preview !== null ? (
                    <>
                        <p className="font-bold">{preview.summary}</p>
                        {values.currency === 'VES' ? <p className="text-ink-2 mt-1">{appliedRateLine(options, values.paid_at, preview)}</p> : null}
                    </>
                ) : (
                    <p className="text-ink-2">Escribe el importe para ver a qué cuotas iría.</p>
                )}
            </section>

            {excess !== null && preview !== null ? (
                <Alert tone="warning" className="flex-col items-start">
                    <span>Sobran {excess} sobre el saldo. No se devuelven como crédito: quedan registrados sin asignar.</span>
                    <CheckboxField
                        label={`Confirmo que registro el pago con ${excess} sin asignar`}
                        name="confirm_excess"
                        checked={confirmed}
                        onChange={(event) => onChange({ confirm_excess: event.target.checked ? preview.unallocatedCents : null })}
                        error={errors.confirm_excess}
                    />
                    <SurplusDestinationFields
                        destinations={options.destinations}
                        teamMembers={options.teamMembers}
                        values={values}
                        errors={errors}
                        onChange={onChange}
                    />
                </Alert>
            ) : errors.confirm_excess !== undefined ? (
                <Alert tone="warning">{errors.confirm_excess}</Alert>
            ) : null}

            <RateConfirmations
                preview={preview}
                values={{ confirm_stale_rate: values.confirm_stale_rate, confirm_rate_deviation: values.confirm_rate_deviation }}
                onChange={(field, token) =>
                    onChange(field === 'confirm_stale_rate' ? { confirm_stale_rate: token } : { confirm_rate_deviation: token })
                }
                errors={errors}
            />
        </>
    );
}
