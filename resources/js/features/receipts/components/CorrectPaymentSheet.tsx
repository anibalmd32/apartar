import { ManualPaymentForm } from '@/features/receipts/components/ManualPaymentForm';
import { type PaymentFieldOptions } from '@/types/layaway-backoffice';
import { type PaymentRow } from '@/types/payment-row';

interface CorrectPaymentSheetProps {
    payment: PaymentRow | null;
    fields: PaymentFieldOptions;
    onClose: () => void;
}

export function CorrectPaymentSheet({ payment, fields, onClose }: CorrectPaymentSheetProps) {
    const correction = payment?.correction ?? null;

    if (payment === null || correction === null) {
        return null;
    }

    return (
        <ManualPaymentForm
            key={correction.storeUrl}
            open
            onClose={onClose}
            title="Editar pago"
            description={`Se anula el pago de ${payment.amountUsd.usd} y se registra con estos datos. En la nota, explica qué corriges.`}
            options={{ ...fields, storeUrl: correction.storeUrl, previewUrl: correction.previewUrl }}
            initial={correction.values}
            submitLabel="Guardar la corrección"
        />
    );
}
