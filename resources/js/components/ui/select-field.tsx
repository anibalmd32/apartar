import { cn } from '@/lib/utils';
import { type ReactNode, type SelectHTMLAttributes, useId } from 'react';

/**
 * Desplegable daisyUI con su etiqueta, su pista y su error
 * (`REQ-QAX-001` RB-5).
 *
 * Hermano de `text-field.tsx` y con sus mismas tres garantías, porque son las
 * mismas tres que se olvidan: etiqueta asociada por `id` generado, error
 * anunciado con `aria-invalid` y `aria-describedby`, y pista enlazada.
 *
 * Las opciones llegan como datos y no como `children` a propósito: un
 * desplegable cuyas opciones se escriben como JSX invita a colar un `<option>`
 * con un valor que el servidor no admite. Aquí la lista es un array tipado, y
 * el sitio donde se declara es el que conoce el enum.
 */

export interface SelectOption {
    readonly value: string;
    readonly label: string;
}

interface SelectFieldProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'children'> {
    label: string;
    options: readonly SelectOption[];
    error?: string;
    hint?: ReactNode;
}

export function SelectField({ label, options, error, hint, id, className, ...props }: SelectFieldProps) {
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

            <select
                id={fieldId}
                className={cn('select w-full', error && 'select-error', className)}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy === '' ? undefined : describedBy}
                {...props}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>

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
