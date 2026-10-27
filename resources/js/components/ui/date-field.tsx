import { type ReactNode, useId } from 'react';
import { DateInput } from './date-input';

interface DateFieldProps {
    label: string;
    value: string;
    onChange: (value: string) => void;
    name?: string;
    min?: string | null;
    max?: string | null;
    required?: boolean;
    disabled?: boolean;
    error?: string;
    hint?: ReactNode;
}

export function DateField({ label, value, onChange, name, min, max, required, disabled, error, hint }: DateFieldProps) {
    const id = useId();
    const errorId = `${id}-error`;
    const hintId = `${id}-hint`;
    const describedBy = [error ? errorId : null, hint ? hintId : null].filter(Boolean).join(' ');

    return (
        <fieldset className="fieldset w-full">
            <label className="fieldset-legend" htmlFor={id}>
                {label}
            </label>

            <DateInput
                id={id}
                name={name}
                value={value}
                onChange={onChange}
                min={min}
                max={max}
                required={required}
                disabled={disabled}
                invalid={Boolean(error)}
                describedBy={describedBy === '' ? undefined : describedBy}
            />

            {hint ? (
                <p id={hintId} className="label text-xs whitespace-normal">
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
