import { SelectField } from '@/components/ui/select-field';
import { type ReceiptReviewProps } from '@/types/receipt';
import { useForm } from '@inertiajs/react';
import { type FormEvent, useId } from 'react';

/**
 * Rechazar un comprobante (`REQ-PAY-006`).
 *
 * Un motivo del catálogo y una explicación de 10 a 500 caracteres (RB-1), que
 * **el cliente leerá tal cual** para saber qué corregir (RB-3) —por eso se
 * dice junto al campo—. El archivo y el registro se conservan (RB-2), y la
 * referencia no queda libre (RB-5). Si el apartado esperaba la inicial y su
 * plazo ya venció, el servidor concede la prórroga de 24 h y lo dice al
 * volver (RB-6).
 */

type Rejection = NonNullable<ReceiptReviewProps['rejection']>;

interface RejectFormValues {
    [key: string]: string;
    code: string;
    reason: string;
}

export function RejectForm({ rejection }: { rejection: Rejection }) {
    const reasonId = useId();
    const form = useForm<RejectFormValues>({ code: '', reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(rejection.url, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3" aria-label="Rechazar el comprobante">
            <SelectField
                label="Motivo"
                name="code"
                options={[{ value: '', label: 'Elige un motivo' }, ...rejection.codes]}
                value={form.data.code}
                onChange={(event) => form.setData('code', event.target.value)}
                error={form.errors.code}
            />

            <div className="flex flex-col gap-1">
                <label className="text-sm font-medium" htmlFor={reasonId}>
                    Explicación para el cliente
                </label>
                <textarea
                    id={reasonId}
                    name="reason"
                    className="textarea textarea-bordered w-full"
                    rows={3}
                    maxLength={500}
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                    aria-invalid={form.errors.reason !== undefined}
                    aria-describedby={`${reasonId}-hint`}
                />
                <span id={`${reasonId}-hint`} className="text-xs opacity-70">
                    El cliente la verá tal cual, para saber qué corregir. Entre 10 y 500 caracteres.
                </span>
                {form.errors.reason !== undefined ? <span className="text-error text-sm">{form.errors.reason}</span> : null}
            </div>

            <button
                type="submit"
                className="btn btn-outline btn-error"
                disabled={form.processing || form.data.code === '' || form.data.reason.trim().length < 10}
            >
                Rechazar el comprobante
            </button>
        </form>
    );
}
