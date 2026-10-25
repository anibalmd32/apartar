import { Button } from '@/components/ui/button';
import { type BackofficeInstallmentRow, type RescheduleOption } from '@/types/layaway-backoffice';
import { useForm } from '@inertiajs/react';
import { useId, useState, type FormEventHandler } from 'react';

interface RescheduleInstallmentDialogProps {
    installment: BackofficeInstallmentRow;
    option: RescheduleOption;
}

export function RescheduleInstallmentDialog({ installment, option }: RescheduleInstallmentDialogProps) {
    const [open, setOpen] = useState(false);
    const { data, setData, patch, processing, errors, reset, clearErrors } = useForm({ due_date: installment.dueDate, reason: '' });
    const titleId = useId();
    const dateId = useId();
    const reasonId = useId();

    const close = () => {
        setOpen(false);
        reset();
        clearErrors();
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        patch(option.url, { preserveScroll: true, onSuccess: close });
    };

    return (
        <>
            <Button variant="ghost" size="sm" onClick={() => setOpen(true)} aria-label={`Reprogramar la cuota ${installment.number}`}>
                Reprogramar
            </Button>

            {open ? (
                <div className="modal modal-open" role="dialog" aria-modal="true" aria-labelledby={titleId}>
                    <form className="modal-box flex flex-col gap-4" onSubmit={submit}>
                        <h2 id={titleId} className="text-[19px] font-bold tracking-[-0.3px]">
                            Reprogramar la cuota {installment.number}
                        </h2>

                        <p className="text-sm">
                            Vence el {installment.dueDateLabel}. Solo cambia la fecha: el importe sigue igual. La nueva fecha tiene que estar{' '}
                            {option.window}.
                        </p>

                        <div className="flex flex-col gap-1">
                            <label htmlFor={dateId} className="fieldset-legend">
                                Nueva fecha
                            </label>
                            <input
                                id={dateId}
                                type="date"
                                className="input w-full"
                                min={option.earliest}
                                max={option.latest ?? undefined}
                                value={data.due_date}
                                onChange={(event) => setData('due_date', event.target.value)}
                                aria-invalid={errors.due_date ? true : undefined}
                            />
                            {errors.due_date ? (
                                <p role="alert" className="text-error text-xs">
                                    {errors.due_date}
                                </p>
                            ) : null}
                        </div>

                        <div className="flex flex-col gap-1">
                            <label htmlFor={reasonId} className="fieldset-legend">
                                Motivo
                            </label>
                            <textarea
                                id={reasonId}
                                className="textarea w-full"
                                rows={3}
                                maxLength={500}
                                value={data.reason}
                                onChange={(event) => setData('reason', event.target.value)}
                                aria-invalid={errors.reason ? true : undefined}
                            />
                            <p className="text-ink-3 text-xs">Entre 10 y 500 caracteres. Queda en el registro de auditoría.</p>
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
                            <Button type="submit" loading={processing}>
                                Reprogramar
                            </Button>
                        </div>
                    </form>
                </div>
            ) : null}
        </>
    );
}
