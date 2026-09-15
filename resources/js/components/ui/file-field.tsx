import { cn } from '@/lib/utils';
import { type InputHTMLAttributes, type ReactNode, useId } from 'react';

/**
 * Campo de archivo daisyUI con su etiqueta, su pista y su error
 * (`REQ-QAX-001` RB-5).
 *
 * Hermano de `text-field.tsx` y con sus mismas garantías, pero **no comparte
 * su envoltorio**: aquel mete el `input` dentro de un `label.input` para poder
 * pintar el prefijo dentro del recuadro, y un selector de archivo ahí dentro
 * se ve roto. daisyUI tiene su propia clase, `file-input`, y es la que hay que
 * usar.
 *
 * Admite una vista previa —`preview`— porque un campo de logo sin ver el logo
 * actual obliga a subirlo otra vez para saber cuál está puesto.
 */

interface FileFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
    label: string;
    hint?: ReactNode;
    error?: string;
    /** Lo que hay guardado hoy: se muestra al lado del selector. */
    preview?: ReactNode;
}

export function FileField({ label, hint, error, preview, id, className, ...props }: FileFieldProps) {
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

            <div className="flex items-center gap-3">
                {preview}

                <input
                    id={fieldId}
                    type="file"
                    className={cn('file-input w-full', error && 'file-input-error', className)}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy === '' ? undefined : describedBy}
                    {...props}
                />
            </div>

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
