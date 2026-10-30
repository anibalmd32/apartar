import { PaymentFields } from '@/features/receipts/components/PaymentFields';
import { useApprovalPreview } from '@/features/receipts/hooks/useApprovalPreview';
import { emptyPaymentValues, type PaymentFieldValues, paymentPending } from '@/features/receipts/lib/payment-fields';
import { type ManualPaymentOptions } from '@/types/layaway-backoffice';
import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

interface ManualPaymentValues extends PaymentFieldValues {
    [key: string]: string | number | File | null;
    idempotency_key: string;
}

export function ManualPaymentForm({ options }: { options: ManualPaymentOptions }) {
    const form = useForm<ManualPaymentValues>({ ...emptyPaymentValues(options), idempotency_key: crypto.randomUUID() });

    const previewState = useApprovalPreview(
        options.previewUrl,
        { amount: form.data.amount, currency: form.data.currency, rate: form.data.rate },
        { amount: '', currency: 'USD', rate: '', preview: null, error: null },
        form.errors.confirm_stale_rate ?? '',
    );

    const blocked = form.processing || paymentPending(options, form.data, previewState);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(options.storeUrl, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.setData((data) => ({ ...data, idempotency_key: crypto.randomUUID() }));
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3" aria-label="Registrar un pago">
            <PaymentFields
                key={form.data.idempotency_key}
                values={form.data}
                errors={form.errors}
                onChange={(patch) => form.setData((data) => ({ ...data, ...patch }))}
                options={options}
                preview={previewState}
            />

            <button type="submit" className="btn btn-primary w-full" disabled={blocked}>
                Registrar el pago
            </button>
        </form>
    );
}
