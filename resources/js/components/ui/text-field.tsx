import { FieldError } from '@/components/ui/field-error';
import { cn } from '@/lib/utils';
import { type InputHTMLAttributes, type ReactNode, useId } from 'react';

interface TextFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'prefix'> {
    label: string;
    error?: string;
    hint?: ReactNode;
    prefix?: string;
    suffix?: string;
}

export function TextField({ label, error, hint, prefix, suffix, id, className, ...props }: TextFieldProps) {
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

                {suffix ? <span className="text-ink-3 shrink-0 text-sm">{suffix}</span> : null}
            </label>

            {hint ? (
                <p id={hintId} className="label text-xs">
                    {hint}
                </p>
            ) : null}

            {error ? <FieldError id={errorId}>{error}</FieldError> : null}
        </fieldset>
    );
}
