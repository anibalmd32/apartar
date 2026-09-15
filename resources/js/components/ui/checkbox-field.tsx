import { cn } from '@/lib/utils';
import { type InputHTMLAttributes, type ReactNode, useId } from 'react';

/**
 * Interruptor daisyUI con su etiqueta y su pista (`REQ-QAX-001` RB-5).
 *
 * La etiqueta **envuelve** al control en lugar de quedar al lado: así el área
 * pulsable es toda la fila, que en un teléfono es la diferencia entre acertar
 * y no. El `id` se genera igual que en los demás átomos, para que
 * `aria-describedby` pueda apuntar a la pista.
 *
 * Se usa `toggle` y no `checkbox` porque lo que gobierna son estados de
 * encendido y apagado —la vitrina, por ejemplo—, y un interruptor dice eso sin
 * leer nada.
 */

interface CheckboxFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
    label: string;
    hint?: ReactNode;
    error?: string;
}

export function CheckboxField({ label, hint, error, id, className, ...props }: CheckboxFieldProps) {
    const generatedId = useId();
    const fieldId = id ?? generatedId;
    const errorId = `${fieldId}-error`;
    const hintId = `${fieldId}-hint`;

    const describedBy = [error ? errorId : null, hint ? hintId : null].filter(Boolean).join(' ');

    return (
        <fieldset className="fieldset w-full">
            <label className="label cursor-pointer justify-start gap-3" htmlFor={fieldId}>
                <input
                    id={fieldId}
                    type="checkbox"
                    className={cn('toggle', error && 'toggle-error', className)}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy === '' ? undefined : describedBy}
                    {...props}
                />

                <span>{label}</span>
            </label>

            {hint ? (
                <p id={hintId} className="label text-xs">
                    {hint}
                </p>
            ) : null}

            {error ? (
                <p id={errorId} role="alert" className="label text-error text-xs">
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
