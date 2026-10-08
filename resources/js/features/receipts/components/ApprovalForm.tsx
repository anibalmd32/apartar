import { Alert } from '@/components/ui/alert';
import { CheckboxField } from '@/components/ui/checkbox-field';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { useApprovalPreview } from '@/features/receipts/hooks/useApprovalPreview';
import { formatRate } from '@/features/receipts/lib/inbox-format';
import { type ReceiptReviewProps } from '@/types/receipt';
import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

/**
 * Aprobar un comprobante (`REQ-PAY-005`), con lo que haría a la vista
 * (`REQ-PAY-004` RB-6).
 *
 * Se precarga con lo que declaró el cliente y el negocio lo corrige si recibió
 * otra cosa (RB-3). La tasa es la vigente de la organización salvo que se
 * escriba otra (SP-7, queda como `manual` y auditada); en dólares no hace
 * falta. Mientras se edita, la vista previa dice a qué cuotas iría y si sobra
 * algo, y **si sobra, el botón no aprueba hasta confirmarlo** (SP-5) —el
 * servidor tampoco: lo rechaza entero—.
 *
 * Los rechazos del servidor —ya procesado, sin tasa, por debajo de la
 * inicial— vuelven por campo con el `useForm` de Inertia.
 */

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
    /** Los centavos de sobrante que se confirmaron; `null` sin confirmar. */
    confirm_excess: number | null;
}

export function ApprovalForm({ approval, receipt, rate, initialPreview, initialError }: ApprovalFormProps) {
    const form = useForm<ApprovalFormValues>({
        amount: receipt.declaredAmount,
        currency: receipt.declaredCurrency,
        rate: '',
        note: '',
        confirm_excess: null,
    });

    const { preview, error, loading } = useApprovalPreview(
        approval.previewUrl,
        { amount: form.data.amount, currency: form.data.currency, rate: form.data.rate },
        { amount: receipt.declaredAmount, currency: receipt.declaredCurrency, rate: '', preview: initialPreview, error: initialError },
    );

    const excess = preview?.unallocated ?? null;
    // Confirmado es **esta** cifra (SP-5): si el sobrante cambia —otro
    // importe, otra tasa—, la casilla se desmarca sola y hay que volver a
    // confirmarlo. El servidor compara la misma cifra.
    const confirmed = preview !== null && excess !== null && form.data.confirm_excess === preview.unallocatedCents;
    const blocked = form.processing || loading || (excess !== null && !confirmed);

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
                        // En dólares no hay tasa que usar: la escrita en bolívares
                        // no viaja, o el pago quedaría como `manual`.
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

            <section aria-label="Vista previa" aria-busy={loading} className="bg-base-200 rounded p-3 text-sm">
                {error !== null ? (
                    <p className="text-error">{error}</p>
                ) : preview !== null ? (
                    <div className="flex flex-col gap-1">
                        <p className="font-medium">{preview.summary}</p>
                        <p className="opacity-70">
                            Entran {preview.amount}
                            {preview.rateSource === 'manual' || form.data.currency === 'VES'
                                ? ` a ${formatRate(preview.rate)} (${preview.rateSourceLabel})`
                                : ''}
                            .
                        </p>
                    </div>
                ) : (
                    <p className="opacity-70">Escribe el importe para ver a qué cuotas iría.</p>
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
                // El servidor vio un sobrante que esta vista previa no enseña
                // —entró otro pago entre medias—: se dice igual.
                <Alert tone="warning">{form.errors.confirm_excess}</Alert>
            ) : null}

            <button type="submit" className="btn btn-primary" disabled={blocked}>
                Aprobar y aplicar el pago
            </button>
        </form>
    );
}
