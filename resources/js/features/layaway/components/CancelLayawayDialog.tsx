import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type MoneyView } from '@/types/money';
import { cilBan } from '@coreui/icons';
import { useForm } from '@inertiajs/react';
import { useId, useState, type FormEventHandler } from 'react';

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
            <Button variant="ghost" onClick={() => setOpen(true)}>
                <Icon icon={cilBan} />
                Cancelar apartado
            </Button>

            {open ? (
                <div className="modal modal-open" role="dialog" aria-modal="true" aria-labelledby={titleId}>
                    <form className="modal-box flex max-w-[560px] flex-col p-0" onSubmit={submit}>
                        <header className="flex items-start gap-3.5 px-6 pt-[22px] pb-4">
                            <IconChip icon={cilBan} tone="error" size={44} className="rounded-[13px]" />
                            <div className="flex min-w-0 flex-col gap-1">
                                <h2 id={titleId} className="text-[19px] leading-tight font-bold tracking-[-0.3px]">
                                    Cancelar este apartado
                                </h2>
                                <p className="text-ink-3 text-[13px]">
                                    El producto vuelve al inventario y el apartado deja de cobrarse. No se calcula ninguna devolución: lo pagado queda
                                    en el historial como constancia.
                                </p>
                            </div>
                        </header>

                        <div className="flex flex-col gap-4 px-6 pt-1 pb-5">
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
                        </div>

                        <div className="bg-surface-2 border-line flex justify-end gap-2.5 border-t px-6 py-4">
                            <Button type="button" variant="secondary" onClick={close}>
                                Volver
                            </Button>
                            <Button type="submit" variant="danger" loading={processing} disabled={hasPayments && !acknowledged}>
                                Cancelar apartado
                            </Button>
                        </div>
                    </form>
                </div>
            ) : null}
        </>
    );
}
