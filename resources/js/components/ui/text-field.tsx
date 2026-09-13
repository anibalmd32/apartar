import { cn } from '@/lib/utils';
import { type InputHTMLAttributes, type ReactNode, useId } from 'react';

/**
 * Campo de texto daisyUI con su etiqueta, su pista y su error
 * (`REQ-QAX-001` RB-5).
 *
 * Es el átomo que más se va a repetir del sistema, así que las tres cosas que
 * siempre se olvidan van resueltas aquí y no en cada formulario:
 *
 *  - el `id` se genera si no se pasa, de modo que la etiqueta **siempre** está
 *    asociada al campo y pulsarla enfoca;
 *  - el error se anuncia con `aria-invalid` y `aria-describedby`, no solo en
 *    rojo: un mensaje que solo existe como color no existe para quien usa
 *    lector de pantalla;
 *  - el `prefix` vive dentro del recuadro del campo, que es como se lee una
 *    URL —`apartar.store/mi-negocio`— en lugar de como dos cosas sueltas.
 */

interface TextFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'prefix'> {
    label: string;
    error?: string;
    hint?: ReactNode;
    /** Texto fijo delante del valor, dentro del recuadro. */
    prefix?: string;
}

export function TextField({ label, error, hint, prefix, id, className, ...props }: TextFieldProps) {
    const generatedId = useId();
    const fieldId = id ?? generatedId;
    const errorId = `${fieldId}-error`;
    const hintId = `${fieldId}-hint`;

    const describedBy = [error ? errorId : null, hint ? hintId : null].filter(Boolean).join(' ');

    return (
        <fieldset className="fieldset w-full">
            <label className="fieldset-legend" htmlFor={fieldId}>
                {label}
            </label>

            <label className={cn('input w-full', error && 'input-error', className)}>
                {prefix ? <span className="shrink-0 opacity-60">{prefix}</span> : null}

                <input
                    id={fieldId}
                    className="grow"
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy === '' ? undefined : describedBy}
                    {...props}
                />
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
