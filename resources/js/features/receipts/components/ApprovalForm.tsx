import { Alert } from '@/components/ui/alert';
import { CheckboxField } from '@/components/ui/checkbox-field';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { StaleRateConfirmation, staleRatePending } from '@/features/receipts/components/StaleRateConfirmation';
import { useApprovalPreview } from '@/features/receipts/hooks/useApprovalPreview';
import { formatRate } from '@/features/receipts/lib/inbox-format';
import { type ReceiptReviewProps } from '@/types/receipt';
import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

type Approval = NonNullable<ReceiptReviewProps['approval']>;

interface ApprovalFormProps {
    approval: Approval;
    receipt: ReceiptReviewProps['receipt'];
    rate: ReceiptReviewProps['rate'];
    initialPreview: ReceiptReviewProps['preview'];
    initialError: ReceiptReviewProps['previewError'];
}

interface ApprovalFormValues {
    [key: string]: string | number | null;
    amount: string;
    currency: string;
    rate: string;
    note: string;
    confirm_excess: number | null;
    confirm_stale_rate: string | null;
}

export function ApprovalForm({ approval, receipt, rate, initialPreview, initialError }: ApprovalFormProps) {
    const form = useForm<ApprovalFormValues>({
        amount: receipt.declaredAmount,
        currency: receipt.declaredCurrency,
        rate: '',
        note: '',
        confirm_excess: null,
        confirm_stale_rate: null,
    });

    const { preview, error, loading } = useApprovalPreview(
        approval.previewUrl,
        { amount: form.data.amount, currency: form.data.currency, rate: form.data.rate },
        { amount: receipt.declaredAmount, currency: receipt.declaredCurrency, rate: '', preview: initialPreview, error: initialError },
        form.errors.confirm_stale_rate ?? '',
    );

    const excess = preview?.unallocated ?? null;
    const confirmed = preview !== null && excess !== null && form.data.confirm_excess === preview.unallocatedCents;
    const blocked = form.processing || loading || (excess !== null && !confirmed) || staleRatePending(preview, form.data.confirm_stale_rate);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(approval.url, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3" aria-label="Aprobar el comprobante">
            <div className="grid gap-2 sm:grid-cols-2">
                <TextField
                    label="Importe recibido"
                    name="amount"
                    inputMode="decimal"
                    value={form.data.amount}
                    onChange={(event) => form.setData('amount', event.target.value)}
                    error={form.errors.amount}
                    hint={`El cliente declaró ${receipt.declared}.`}
                />
                <SelectField
                    label="Moneda"
                    name="currency"
                    options={approval.currencies}
                    value={form.data.currency}
                    onChange={(event) => {
                        form.setData((data) => ({ ...data, currency: event.target.value, rate: event.target.value === 'USD' ? '' : data.rate }));
                    }}
                    error={form.errors.currency}
                />
            </div>

            {form.data.currency === 'VES' ? (
                <TextField
                    label="Tasa (opcional)"
                    name="rate"
                    inputMode="decimal"
                    value={form.data.rate}
                    onChange={(event) => form.setData('rate', event.target.value)}
                    error={form.errors.rate}
                    hint={
                        rate === null
                            ? 'No hay tasa vigente: escribe la que usaste para aprobar en bolívares.'
                            : `Vacía usa la vigente: ${formatRate(rate.rate)} (${rate.sourceLabel}). Si escribes otra, queda registrada como manual.`
                    }
                />
            ) : null}

            <TextField
                label="Nota (opcional)"
                name="note"
                value={form.data.note}
                onChange={(event) => form.setData('note', event.target.value)}
                error={form.errors.note}
            />

            <section
                aria-label="Vista previa"
                aria-busy={loading}
                className={error !== null ? 'bg-error-soft rounded-xl p-3.5 text-[13px]' : 'bg-success-soft rounded-xl p-3.5 text-[13px]'}
            >
                {error !== null ? (
                    <p className="text-error">{error}</p>
                ) : preview !== null ? (
                    <div className="flex flex-col gap-1">
                        <p className="font-bold">{preview.summary}</p>
                        <p className="text-ink-2">
                            Entran {preview.amount}
                            {preview.rateSource === 'manual' || form.data.currency === 'VES'
                                ? ` a ${formatRate(preview.rate)} (${preview.rateSourceLabel})`
                                : ''}
                            .
                        </p>
                    </div>
                ) : (
                    <p className="text-ink-2">Escribe el importe para ver a qué cuotas iría.</p>
                )}
            </section>

            {excess !== null ? (
                <Alert tone="warning" className="flex-col items-start">
                    <span>Sobran {excess} sobre el saldo. No se devuelven como crédito: quedan registrados sin asignar.</span>
                    <CheckboxField
                        label={`Confirmo que apruebo con ${excess} sin asignar`}
                        name="confirm_excess"
                        checked={confirmed}
                        onChange={(event) => form.setData('confirm_excess', event.target.checked ? (preview?.unallocatedCents ?? null) : null)}
                        error={form.errors.confirm_excess}
                    />
                </Alert>
            ) : form.errors.confirm_excess !== undefined ? (
                <Alert tone="warning">{form.errors.confirm_excess}</Alert>
            ) : null}

            <StaleRateConfirmation
                staleRate={preview?.staleRate ?? null}
                confirmed={form.data.confirm_stale_rate}
                onChange={(token) => form.setData('confirm_stale_rate', token)}
                error={form.errors.confirm_stale_rate}
            />

            <button type="submit" className="btn btn-success btn-lg w-full" disabled={blocked}>
                Aprobar y aplicar el pago
            </button>
        </form>
    );
}
