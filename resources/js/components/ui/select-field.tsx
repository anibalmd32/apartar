import { cn } from '@/lib/utils';
import { type ReactNode, type SelectHTMLAttributes, useId } from 'react';

export interface SelectOption {
    readonly value: string;
    readonly label: string;
}

interface SelectFieldProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'children'> {
    label: string;
    options: readonly SelectOption[];
    error?: string;
    hint?: ReactNode;
    hideLabel?: boolean;
    inline?: boolean;
}

export function SelectField({ label, options, error, hint, hideLabel = false, inline = false, id, className, ...props }: SelectFieldProps) {
    const generatedId = useId();
    const fieldId = id ?? generatedId;
    const errorId = `${fieldId}-error`;
    const hintId = `${fieldId}-hint`;

    const describedBy = [error ? errorId : null, hint ? hintId : null].filter(Boolean).join(' ');

    return (
        <fieldset className={inline ? 'fieldset w-auto py-0' : 'fieldset w-full'}>
            <label className={hideLabel ? 'sr-only' : 'fieldset-legend'} htmlFor={fieldId}>
                {label}
            </label>

            <select
                id={fieldId}
                className={cn('select', inline ? 'w-auto' : 'w-full', error && 'select-error', className)}
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
