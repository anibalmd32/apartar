import { Button } from '@/components/ui/button';
import { DateInput } from '@/components/ui/date-input';
import { Modal } from '@/components/ui/modal';
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
            <Button
                variant="ghost"
                size="sm"
                className="-mr-3"
                onClick={() => setOpen(true)}
                aria-label={`Reprogramar la cuota ${installment.number}`}
            >
                Reprogramar
            </Button>

            <Modal
                open={open}
                onClose={processing ? () => undefined : close}
                onSubmit={submit}
                size="md"
                title={`Reprogramar la cuota ${installment.number}`}
                description={`Vence el ${installment.dueDateLabel}. Solo cambia la fecha: el importe sigue igual. La nueva fecha tiene que estar ${option.window}.`}
                actions={
                    <>
                        <Button type="button" variant="secondary" onClick={close} disabled={processing}>
                            Volver
                        </Button>
                        <Button type="submit" loading={processing}>
                            Reprogramar
                        </Button>
                    </>
                }
            >
                <div className="flex flex-col gap-1">
                    <label htmlFor={dateId} className="fieldset-legend">
                        Nueva fecha
                    </label>
                    <DateInput
                        id={dateId}
                        required
                        min={option.earliest}
                        max={option.latest}
                        value={data.due_date}
                        onChange={(value) => setData('due_date', value)}
                        invalid={Boolean(errors.due_date)}
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
            </Modal>
        </>
    );
}
