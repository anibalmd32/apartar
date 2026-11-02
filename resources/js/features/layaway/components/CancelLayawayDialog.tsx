import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type MoneyView } from '@/types/money';
import { useForm } from '@inertiajs/react';
import { useId, useState, type FormEventHandler } from 'react';

interface CancelLayawayDialogProps {
    cancelUrl: string;
    paid: MoneyView;
    open: boolean;
    onClose: () => void;
}

export function CancelLayawayDialog({ cancelUrl, paid, open, onClose }: CancelLayawayDialogProps) {
    const [acknowledged, setAcknowledged] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ reason: '' });
    const reasonId = useId();
    const hasPayments = paid.cents > 0;

    const close = () => {
        onClose();
        setAcknowledged(false);
        reset();
        clearErrors();
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(cancelUrl, { preserveScroll: true, onSuccess: close });
    };

    return (
        <Modal
            open={open}
            onClose={processing ? () => undefined : close}
            onSubmit={submit}
            size="md"
            tone="danger"
            title="Cancelar este apartado"
            description="El producto vuelve al inventario y el apartado deja de cobrarse. No se calcula ninguna devolución: lo pagado queda en el historial como constancia."
            actions={
                <>
                    <Button type="button" variant="secondary" onClick={close} disabled={processing}>
                        Volver
                    </Button>
                    <Button type="submit" variant="danger" loading={processing} disabled={hasPayments && !acknowledged}>
                        Cancelar apartado
                    </Button>
                </>
            }
        >
            {hasPayments ? (
                <label className="bg-warning-soft border-warning/30 flex items-start gap-3 rounded-[14px] border px-4 py-3.5 text-[13px]">
                    <input
                        type="checkbox"
                        className="checkbox checkbox-sm mt-0.5"
                        checked={acknowledged}
                        onChange={(event) => setAcknowledged(event.target.checked)}
                    />
                    <span className="text-ink-2">
                        El cliente ha pagado{' '}
                        <strong className="text-base-content">
                            <MoneyLabel money={paid} />
                        </strong>
                        , y entiendo que cualquier devolución se gestiona fuera de Apartar.
                    </span>
                </label>
            ) : null}

            <div className="flex flex-col gap-1">
                <label htmlFor={reasonId} className="fieldset-legend flex items-center gap-2">
                    Motivo <span className="badge badge-neutral badge-count">Solo tu equipo</span>
                </label>
                <textarea
                    id={reasonId}
                    className="textarea w-full"
                    rows={3}
                    maxLength={500}
                    value={data.reason}
                    onChange={(event) => setData('reason', event.target.value)}
                    aria-invalid={errors.reason ? true : undefined}
                    aria-describedby={`${reasonId}-hint`}
                />
                <p id={`${reasonId}-hint`} className="text-ink-3 text-xs">
                    Entre 10 y 500 caracteres. Solo lo ve tu equipo: el cliente verá «cancelado por el negocio».
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
