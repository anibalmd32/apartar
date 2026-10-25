import { Alert } from '@/components/ui/alert';
import { CheckboxField } from '@/components/ui/checkbox-field';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { StaleRateConfirmation, staleRatePending } from '@/features/receipts/components/StaleRateConfirmation';
import { useApprovalPreview } from '@/features/receipts/hooks/useApprovalPreview';
import { formatRate } from '@/features/receipts/lib/inbox-format';
import { type ManualPaymentOptions } from '@/types/layaway-backoffice';
import { useForm } from '@inertiajs/react';
import { type FormEvent, useId } from 'react';

interface ManualPaymentValues {
    [key: string]: string | number | null;
    method: string;
    payment_account: string;
    amount: string;
    currency: string;
    rate: string;
    paid_at: string;
    note: string;
    idempotency_key: string;
    confirm_excess: number | null;
    confirm_stale_rate: string | null;
}

export function ManualPaymentForm({ options }: { options: ManualPaymentOptions }) {
    const noteId = useId();
    const form = useForm<ManualPaymentValues>({
        method: '',
        payment_account: '',
        amount: '',
        currency: 'USD',
        rate: '',
        paid_at: options.paidAt.max,
        note: '',
        idempotency_key: crypto.randomUUID(),
        confirm_excess: null,
        confirm_stale_rate: null,
    });

    const { preview, error, loading } = useApprovalPreview(
        options.previewUrl,
        { amount: form.data.amount, currency: form.data.currency, rate: form.data.rate },
        { amount: '', currency: 'USD', rate: '', preview: null, error: null },
        form.errors.confirm_stale_rate ?? '',
    );

    const excess = preview?.unallocated ?? null;
    const confirmed = preview !== null && excess !== null && form.data.confirm_excess === preview.unallocatedCents;
    const blocked =
        form.processing ||
        loading ||
        form.data.method === '' ||
        (excess !== null && !confirmed) ||
        staleRatePending(preview, form.data.confirm_stale_rate);

    const chooseMethod = (value: string) => {
        const method = options.methods.find((option) => option.value === value);

        form.setData((data) => ({
            ...data,
            method: value,
            currency: method?.defaultCurrency ?? data.currency,
            rate: method?.defaultCurrency === 'USD' ? '' : data.rate,
        }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(options.storeUrl, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.setData((data) => ({ ...data, idempotency_key: crypto.randomUUID() }));
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3" aria-label="Registrar un pago">
            <div className="grid gap-2 sm:grid-cols-2">
                <SelectField
                    label="Método"
                    name="method"
                    options={[{ value: '', label: 'Elige cómo te pagaron' }, ...options.methods]}
                    value={form.data.method}
                    onChange={(event) => chooseMethod(event.target.value)}
                    error={form.errors.method}
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
                    value={form.data.payment_account}
                    onChange={(event) => form.setData('payment_account', event.target.value)}
                    error={form.errors.payment_account}
                />
                <TextField
                    label="Importe"
                    name="amount"
                    inputMode="decimal"
                    value={form.data.amount}
                    onChange={(event) => form.setData('amount', event.target.value)}
                    error={form.errors.amount}
                />
                <SelectField
                    label="Moneda"
                    name="currency"
                    options={options.currencies}
                    value={form.data.currency}
                    onChange={(event) =>
                        form.setData((data) => ({ ...data, currency: event.target.value, rate: event.target.value === 'USD' ? '' : data.rate }))
                    }
                    error={form.errors.currency}
                />
                {form.data.currency === 'VES' ? (
                    <TextField
                        label="Tasa (opcional)"
                        name="rate"
                        inputMode="decimal"
                        value={form.data.rate}
                        onChange={(event) => form.setData('rate', event.target.value)}
                        error={form.errors.rate}
                        hint={
                            options.rate === null
                                ? 'No hay tasa vigente: escribe la que usaste.'
                                : `Vacía usa la vigente: ${formatRate(options.rate.rate)} (${options.rate.sourceLabel}).`
                        }
                    />
                ) : null}
                <TextField
                    label="Fecha del pago"
                    name="paid_at"
                    type="date"
                    min={options.paidAt.min}
                    max={options.paidAt.max}
                    value={form.data.paid_at}
                    onChange={(event) => form.setData('paid_at', event.target.value)}
                    error={form.errors.paid_at}
                />
            </div>

            <div className="flex flex-col gap-1">
                <label className="fieldset-legend" htmlFor={noteId}>
                    Nota
                </label>
                <textarea
                    id={noteId}
                    name="note"
                    className="textarea w-full"
                    rows={2}
                    maxLength={500}
                    value={form.data.note}
                    onChange={(event) => form.setData('note', event.target.value)}
                    aria-invalid={form.errors.note !== undefined}
                    aria-describedby={`${noteId}-hint`}
                />
                <span id={`${noteId}-hint`} className="text-ink-3 text-xs">
                    Obligatoria: quién lo recibió, dónde y cómo. Es la única evidencia de un pago sin comprobante.
                </span>
                {form.errors.note !== undefined ? <span className="text-error text-sm">{form.errors.note}</span> : null}
            </div>

            <section aria-label="Vista previa del pago" aria-busy={loading} className="bg-success-soft rounded-xl p-3.5 text-[13px]">
                {error !== null ? (
                    <p className="text-error">{error}</p>
                ) : preview !== null ? (
                    <p className="font-bold">{preview.summary}</p>
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
                        onChange={(event) => form.setData('confirm_excess', event.target.checked ? preview.unallocatedCents : null)}
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

            <button type="submit" className="btn btn-primary w-full" disabled={blocked}>
                Registrar el pago
            </button>
        </form>
    );
}
