import { Button } from '@/components/ui/button';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type MoneyView } from '@/types/money';
import { useForm } from '@inertiajs/react';
import { useId, useState, type FormEventHandler } from 'react';

/**
 * Cancelar un apartado desde el backoffice (`REQ-LAY-009`).
 *
 * Pide el motivo —de 10 a 500 caracteres, el servidor lo vuelve a comprobar
 * (RB-2, SP-2)— y, si el cliente ya pagó algo, **lo dice antes de confirmar** y
 * pide marcar que se ha leído (RB-7): cancelar no devuelve nada desde Apartar
 * (RB-5), y un clic accidental sobre un apartado con abonos es lo caro de
 * deshacer. Sin pagos, basta el motivo (SP-6).
 *
 * El motivo es interno: el cliente verá «cancelado por el negocio» (RB-8), y
 * el diálogo lo avisa para que nadie escriba pensando que lo va a leer.
 */

interface CancelLayawayDialogProps {
    cancelUrl: string;
    paid: MoneyView;
}

export function CancelLayawayDialog({ cancelUrl, paid }: CancelLayawayDialogProps) {
    const [open, setOpen] = useState(false);
    const [acknowledged, setAcknowledged] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ reason: '' });
    const titleId = useId();
    const reasonId = useId();
    const hasPayments = paid.cents > 0;

    const close = () => {
        setOpen(false);
        setAcknowledged(false);
        reset();
        clearErrors();
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(cancelUrl, { preserveScroll: true, onSuccess: close });
    };

    return (
        <>
            <Button variant="ghost" className="text-error" onClick={() => setOpen(true)}>
                Cancelar apartado
            </Button>

            {open ? (
                <div className="modal modal-open" role="dialog" aria-modal="true" aria-labelledby={titleId}>
                    <form className="modal-box flex flex-col gap-4" onSubmit={submit}>
                        <h2 id={titleId} className="text-lg font-semibold">
                            Cancelar este apartado
                        </h2>

                        <p className="text-sm">
                            El producto vuelve al inventario y el apartado deja de cobrarse. No se calcula ninguna devolución: lo pagado queda en el
                            historial como constancia.
                        </p>

                        {hasPayments ? (
                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    className="checkbox checkbox-sm mt-0.5"
                                    checked={acknowledged}
                                    onChange={(event) => setAcknowledged(event.target.checked)}
                                />
                                <span>
                                    El cliente ha pagado <MoneyLabel money={paid} />, y entiendo que cualquier devolución se gestiona fuera de
                                    Apartar.
                                </span>
                            </label>
                        ) : null}

                        <div className="flex flex-col gap-1">
                            <label htmlFor={reasonId} className="text-sm font-medium">
                                Motivo
                            </label>
                            <textarea
                                id={reasonId}
                                className="textarea textarea-bordered w-full"
                                rows={3}
                                maxLength={500}
                                value={data.reason}
                                onChange={(event) => setData('reason', event.target.value)}
                                aria-invalid={errors.reason ? true : undefined}
                                aria-describedby={`${reasonId}-hint`}
                            />
                            <p id={`${reasonId}-hint`} className="text-xs opacity-70">
                                Entre 10 y 500 caracteres. Solo lo ve tu equipo: el cliente verá «cancelado por el negocio».
                            </p>
                            {errors.reason ? (
                                <p role="alert" className="text-error text-xs">
                                    {errors.reason}
                                </p>
                            ) : null}
                        </div>

                        <div className="modal-action">
                            <Button type="button" variant="ghost" onClick={close}>
                                Volver
                            </Button>
                            <Button type="submit" className="btn-error" loading={processing} disabled={hasPayments && !acknowledged}>
                                Cancelar apartado
                            </Button>
                        </div>
                    </form>
                </div>
            ) : null}
        </>
    );
}
