import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { appliedTo } from '@/features/receipts/lib/payment-applied';
import { type PaymentRow } from '@/types/receipt';
import { useForm } from '@inertiajs/react';
import { useId, type FormEventHandler } from 'react';

interface VoidPaymentSheetProps {
    payment: PaymentRow | null;
    reopens: boolean;
    onClose: () => void;
}

function consequences(payment: PaymentRow, reopens: boolean): string[] {
    const covered = appliedTo(payment);

    return [
        covered !== null ? `Se descuenta de lo que cubrió: ${covered}.` : 'Deja de contar en lo pagado del apartado.',
        ...(payment.fromReceipt === true ? ['El comprobante vuelve a la bandeja, pendiente de revisión.'] : []),
        ...(reopens ? ['El apartado estaba pagado: se reabre con el saldo que vuelve a deber.'] : []),
        'El pago queda en el historial, tachado y con el motivo. El cliente deja de verlo.',
    ];
}

export function VoidPaymentSheet({ payment, reopens, onClose }: VoidPaymentSheetProps) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ reason: '' });
    const reasonId = useId();
    const serverError = (errors as Record<string, string | undefined>).payment;

    const close = () => {
        onClose();
        reset();
        clearErrors();
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (payment?.voidUrl) {
            post(payment.voidUrl, { preserveScroll: true, onSuccess: close });
        }
    };

    return (
        <Modal
            open={payment !== null}
            onClose={processing ? () => undefined : close}
            onSubmit={submit}
            size="md"
            tone="danger"
            title="Anular pago"
            description={payment !== null ? `${payment.amountUsd.usd} del ${payment.settledAt} · ${payment.method}` : undefined}
            actions={
                <>
                    <Button type="button" variant="secondary" onClick={close} disabled={processing}>
                        Volver
                    </Button>
                    <Button type="submit" variant="danger" loading={processing}>
                        Anular pago
                    </Button>
                </>
            }
        >
            {serverError ? <Alert tone="error">{serverError}</Alert> : null}
            {payment !== null ? (
                <ul className="text-ink-2 flex list-disc flex-col gap-1.5 pl-5 text-[13px]" aria-label="Qué pasa al anular">
                    {consequences(payment, reopens).map((line) => (
                        <li key={line}>{line}</li>
                    ))}
                </ul>
            ) : null}
            <div className="flex flex-col gap-1">
                <label htmlFor={reasonId} className="fieldset-legend flex items-center gap-2">
                    Motivo <span className="badge badge-neutral badge-count">Solo tu equipo</span>
                </label>
                <textarea
                    id={reasonId}
                    className="textarea w-full"
                    rows={3}
                    required
                    minLength={10}
                    maxLength={500}
                    value={data.reason}
                    onChange={(event) => setData('reason', event.target.value)}
                    aria-invalid={errors.reason ? true : undefined}
                    aria-describedby={`${reasonId}-hint`}
                />
                <p id={`${reasonId}-hint`} className="text-ink-3 text-xs">
                    Entre 10 y 500 caracteres. Por ejemplo: «El comprobante era de otro cliente».
                </p>
                {errors.reason ? (
                    <p role="alert" className="text-error text-xs">
                        {errors.reason}
                    </p>
                ) : null}
            </div>
        </Modal>
    );
}
