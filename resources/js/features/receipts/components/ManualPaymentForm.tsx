import { Button } from '@/components/ui/button';
import { Chip } from '@/components/ui/chip';
import { Modal } from '@/components/ui/modal';
import { PaymentFields } from '@/features/receipts/components/PaymentFields';
import { useApprovalPreview } from '@/features/receipts/hooks/useApprovalPreview';
import { emptyPaymentValues, type PaymentFieldValues, paymentPending } from '@/features/receipts/lib/payment-fields';
import { type PaymentSuggestion } from '@/features/receipts/lib/payment-suggestions';
import { type ManualPaymentOptions } from '@/types/layaway-backoffice';
import { useForm } from '@inertiajs/react';
import { type FormEvent, type ReactNode, useState } from 'react';

interface ManualPaymentValues extends PaymentFieldValues {
    [key: string]: string | number | File | null;
    idempotency_key: string;
}

interface ManualPaymentFormProps {
    options: ManualPaymentOptions;
    open: boolean;
    onClose: () => void;
    title: ReactNode;
    description?: ReactNode;
    suggestions?: readonly PaymentSuggestion[];
    initial?: Partial<PaymentFieldValues>;
    submitLabel?: string;
    extra?: ReactNode;
}

export function ManualPaymentForm({
    options,
    open,
    onClose,
    title,
    description,
    suggestions = [],
    initial,
    submitLabel = 'Registrar el pago',
    extra,
}: ManualPaymentFormProps) {
    const form = useForm<ManualPaymentValues>({ ...emptyPaymentValues(options), ...initial, idempotency_key: crypto.randomUUID() });

    const previewState = useApprovalPreview(
        `${options.previewUrl}${options.previewUrl.includes('?') ? '&' : '?'}${new URLSearchParams({ paid_at: form.data.paid_at }).toString()}`,
        { amount: form.data.amount, currency: form.data.currency, rate: form.data.rate },
        { amount: '', currency: 'USD', rate: '', preview: null, error: null },
        form.errors.confirm_stale_rate ?? '',
    );

    const [reading, setReading] = useState(false);
    const blocked = form.processing || reading || paymentPending(options, form.data, previewState);

    const restart = () => {
        form.reset();
        form.clearErrors();
        form.setData((data) => ({ ...data, idempotency_key: crypto.randomUUID() }));
    };

    const close = () => {
        if (form.processing) {
            return;
        }

        onClose();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(options.storeUrl, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                restart();
                onClose();
            },
        });
    };

    return (
        <Modal
            open={open}
            onClose={close}
            onSubmit={submit}
            title={title}
            description={description}
            actions={
                <>
                    <Button type="button" variant="secondary" onClick={close} disabled={form.processing}>
                        Cancelar
                    </Button>
                    <Button type="submit" loading={form.processing} disabled={blocked}>
                        {submitLabel}
                    </Button>
                </>
            }
        >
            <section aria-label="Registrar un pago" className="flex flex-col gap-3">
                {extra}
                {suggestions.length > 0 ? (
                    <div role="group" aria-label="Rellenar el importe" className="flex flex-wrap gap-2">
                        {suggestions.map((suggestion) => (
                            <Chip
                                key={suggestion.key}
                                active={form.data.currency === 'USD' && form.data.amount === suggestion.amount}
                                onClick={() => form.setData((data) => ({ ...data, amount: suggestion.amount, currency: 'USD', rate: '' }))}
                            >
                                {suggestion.label}
                            </Chip>
                        ))}
                    </div>
                ) : null}

                <PaymentFields
                    key={form.data.idempotency_key}
                    values={form.data}
                    errors={form.errors}
                    onChange={(patch) =>
                        form.setData((data) => {
                            const shortcut = suggestions.some((suggestion) => suggestion.amount === data.amount);
                            const switched = patch.currency !== undefined && patch.currency !== data.currency;

                            return { ...data, ...patch, ...(switched && shortcut && patch.amount === undefined ? { amount: '' } : {}) };
                        })
                    }
                    options={options}
                    preview={previewState}
                    onReadingChange={setReading}
                />
            </section>
        </Modal>
    );
}
